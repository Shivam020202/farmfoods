const fs = require('fs');
const path = require('path');

function getSupabaseConfig() {
    let url = process.env.SUPABASE_URL || '';
    // Supabase REST API lives at https://<ref>.supabase.co — strip pooler/port/db parts
    if (url && !url.includes('supabase.co') && !url.includes('/rest/v1')) {
        url = '';
    }
    return {
        url,
        anonKey: process.env.SUPABASE_ANON_KEY || '',
        serviceRoleKey: process.env.SUPABASE_SERVICE_ROLE_KEY || '',
    };
}

function fallbackBookingsPath() {
    return path.join(__dirname, 'demo_bookings.json');
}

function loadFallbackBookings() {
    try {
        const filePath = fallbackBookingsPath();
        if (!fs.existsSync(filePath)) return [];
        const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
        return Array.isArray(data) ? data : [];
    } catch (err) {
        console.warn('[bookings] loadFallbackBookings skipped:', err.code || err.message);
        return [];
    }
}

function saveFallbackBookings(bookings) {
    // On read-only filesystems (e.g. Vercel serverless) this will throw EROFS.
    // Treat it as a soft failure — Supabase is the source of truth in production.
    try {
        fs.writeFileSync(fallbackBookingsPath(), JSON.stringify(bookings, null, 2));
        return true;
    } catch (err) {
        console.warn('[bookings] saveFallbackBookings skipped:', err.code || err.message);
        return false;
    }
}

async function supabaseRequest(method, apiPath, payload = {}, query = {}) {
    const cfg = getSupabaseConfig();
    if (!cfg.url || !cfg.serviceRoleKey) {
        return { ok: false, status: 503, message: 'Supabase not configured', fallback: true };
    }

    let url = cfg.url.replace(/\/+$/, '') + '/rest/v1/' + apiPath.replace(/^\/+/, '');
    if (Object.keys(query).length > 0) {
        url += '?' + new URLSearchParams(query).toString();
    }

    const headers = {
        'Content-Type': 'application/json',
        'apikey': cfg.anonKey,
        'Authorization': 'Bearer ' + cfg.serviceRoleKey,
        'Prefer': 'return=representation',
    };

    const fetchOptions = { method, headers };
    if (method !== 'GET' && payload && Object.keys(payload).length > 0) {
        fetchOptions.body = JSON.stringify(payload);
    }

    try {
        const response = await fetch(url, fetchOptions);
        const text = await response.text();
        let body;
        try { body = JSON.parse(text); } catch { body = text; }
        return {
            ok: response.ok,
            status: response.status,
            body,
            raw: text,
        };
    } catch (err) {
        return { ok: false, status: 500, message: 'Fetch error: ' + err.message };
    }
}

module.exports = async (req, res) => {
    res.setHeader('Content-Type', 'application/json');

    const method = req.method;

    if (method === 'GET') {
        const result = await supabaseRequest('GET', 'farm_visit_bookings', {}, {
            select: 'id,lead_name,phone,email,visit_date,slot_label,attendee_count,status,feedback_message,created_at',
            order: 'created_at.desc',
        });

        if (result.ok && Array.isArray(result.body)) {
            return res.status(200).json({ ok: true, body: result.body });
        }

        // Fall back to local demo bookings if Supabase is missing or errored
        return res.status(200).json({ ok: true, body: loadFallbackBookings(), fallback: true });
    }

    if (method === 'POST') {
        let input = (req.body && Object.keys(req.body).length > 0) ? req.body : {};
        if (Object.keys(input).length === 0) {
            try {
                const chunks = [];
                for await (const chunk of req) chunks.push(chunk);
                if (chunks.length > 0) {
                    input = JSON.parse(Buffer.concat(chunks).toString()) || {};
                }
            } catch { input = {}; }
        }

        const required = ['lead_name', 'phone', 'visit_date', 'slot_label', 'attendee_count'];
        for (const field of required) {
            if (!input[field] || String(input[field]).trim() === '') {
                return res.status(422).json({ ok: false, message: 'Missing required field: ' + field });
            }
        }

        const payload = {
            lead_name: String(input.lead_name).trim(),
            phone: String(input.phone).trim(),
            email: String(input.email || '').trim(),
            visit_date: String(input.visit_date).trim(),
            slot_label: String(input.slot_label).trim(),
            slot_time: String(input.slot_time || '').trim(),
            attendee_count: parseInt(input.attendee_count, 10),
            attendee_details: input.attendee_details || [],
            status: 'pending',
            feedback_message: String(input.feedback_message || '').trim(),
            source: 'farmmade-frontend',
            verified_otp: !!input.otp_verified,
            created_at: new Date().toISOString(),
        };

        const result = await supabaseRequest('POST', 'farm_visit_bookings', payload);
        if (!result.ok) {
            const bookings = loadFallbackBookings();
            const booking = {
                id: 'demo-' + require('crypto').randomBytes(3).toString('hex'),
                created_at: new Date().toISOString(),
                ...payload,
            };
            bookings.push(booking);
            saveFallbackBookings(bookings);
            return res.status(200).json({
                ok: true,
                message: 'Booking created successfully.',
                booking,
                fallback: true,
                persisted: false,
                warning: 'Booking stored locally only. It will not persist between requests. Connect a working Supabase project to enable real persistence.'
            });
        }

        return res.status(200).json({
            ok: true,
            message: 'Booking created successfully.',
            booking: (result.body && result.body[0]) || payload,
            persisted: true,
        });
    }

    if (method === 'PATCH') {
        let input = (req.body && Object.keys(req.body).length > 0) ? req.body : {};
        if (Object.keys(input).length === 0) {
            try {
                const chunks = [];
                for await (const chunk of req) chunks.push(chunk);
                if (chunks.length > 0) {
                    input = JSON.parse(Buffer.concat(chunks).toString()) || {};
                }
            } catch { input = {}; }
        }

        if (!input.id) {
            return res.status(422).json({ ok: false, message: 'Missing booking id.' });
        }

        const payload = {};
        if (input.status !== undefined) payload.status = input.status;
        if (input.admin_note !== undefined) payload.admin_note = input.admin_note;
        if (input.feedback_message !== undefined) payload.feedback_message = input.feedback_message;

        const result = await supabaseRequest('PATCH', 'farm_visit_bookings?id=eq.' + encodeURIComponent(input.id), payload);
        if (!result.ok) {
            const bookings = loadFallbackBookings();
            for (const booking of bookings) {
                if (booking.id === input.id) {
                    Object.assign(booking, payload);
                }
            }
            saveFallbackBookings(bookings);
            return res.status(200).json({ ok: true, message: 'Booking updated successfully.', booking: null, fallback: true });
        }

        return res.status(200).json({ ok: true, message: 'Booking updated successfully.', booking: (result.body && result.body[0]) || null });
    }

    if (method === 'DELETE') {
        let input = (req.body && Object.keys(req.body).length > 0) ? req.body : {};
        if (Object.keys(input).length === 0) {
            try {
                const chunks = [];
                for await (const chunk of req) chunks.push(chunk);
                if (chunks.length > 0) {
                    input = JSON.parse(Buffer.concat(chunks).toString()) || {};
                }
            } catch { input = {}; }
        }

        if (!input.id) {
            return res.status(422).json({ ok: false, message: 'Missing booking id.' });
        }

        const result = await supabaseRequest('DELETE', 'farm_visit_bookings?id=eq.' + encodeURIComponent(input.id));
        if (!result.ok) {
            let bookings = loadFallbackBookings();
            bookings = bookings.filter(b => b.id !== input.id);
            saveFallbackBookings(bookings);
            return res.status(200).json({ ok: true, message: 'Booking deleted successfully.', fallback: true });
        }

        return res.status(200).json({ ok: true, message: 'Booking deleted successfully.' });
    }

    return res.status(405).json({ ok: false, message: 'Method not allowed.' });
};
