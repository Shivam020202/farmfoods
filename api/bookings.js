const fs = require('fs');
const path = require('path');

function getSupabaseConfig() {
    return {
        url: process.env.SUPABASE_URL || '',
        anonKey: process.env.SUPABASE_ANON_KEY || '',
        serviceRoleKey: process.env.SUPABASE_SERVICE_ROLE_KEY || '',
    };
}

function fallbackBookingsPath() {
    return path.join(__dirname, 'demo_bookings.json');
}

function loadFallbackBookings() {
    const filePath = fallbackBookingsPath();
    if (!fs.existsSync(filePath)) return [];
    try {
        const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
        return Array.isArray(data) ? data : [];
    } catch {
        return [];
    }
}

function saveFallbackBookings(bookings) {
    fs.writeFileSync(fallbackBookingsPath(), JSON.stringify(bookings, null, 2));
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

        if (!result.ok && result.fallback) {
            return res.status(200).json({ ok: true, body: loadFallbackBookings() });
        }

        return res.status(result.status || 500).json(result);
    }

    if (method === 'POST') {
        let input = {};
        try {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            input = JSON.parse(Buffer.concat(chunks).toString()) || {};
        } catch { input = {}; }

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
        if (!result.ok && result.fallback) {
            const bookings = loadFallbackBookings();
            const booking = {
                id: 'demo-' + require('crypto').randomBytes(3).toString('hex'),
                created_at: new Date().toISOString(),
                ...payload,
            };
            bookings.push(booking);
            saveFallbackBookings(bookings);
            return res.status(200).json({ ok: true, message: 'Booking created successfully.', booking });
        }

        if (!result.ok) {
            return res.status(result.status || 500).json({ ok: false, message: 'Booking insert failed', details: result });
        }

        return res.status(200).json({
            ok: true,
            message: 'Booking created successfully.',
            booking: (result.body && result.body[0]) || payload,
        });
    }

    if (method === 'PATCH') {
        let input = {};
        try {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            input = JSON.parse(Buffer.concat(chunks).toString()) || {};
        } catch { input = {}; }

        if (!input.id) {
            return res.status(422).json({ ok: false, message: 'Missing booking id.' });
        }

        const payload = {};
        if (input.status !== undefined) payload.status = input.status;
        if (input.admin_note !== undefined) payload.admin_note = input.admin_note;
        if (input.feedback_message !== undefined) payload.feedback_message = input.feedback_message;

        const result = await supabaseRequest('PATCH', 'farm_visit_bookings?id=eq.' + encodeURIComponent(input.id), payload);
        if (!result.ok && result.fallback) {
            const bookings = loadFallbackBookings();
            for (const booking of bookings) {
                if (booking.id === input.id) {
                    Object.assign(booking, payload);
                }
            }
            saveFallbackBookings(bookings);
            return res.status(200).json({ ok: true, message: 'Booking updated successfully.', booking: null });
        }

        if (!result.ok) {
            return res.status(result.status || 500).json({ ok: false, message: 'Booking update failed', details: result });
        }

        return res.status(200).json({ ok: true, message: 'Booking updated successfully.', booking: (result.body && result.body[0]) || null });
    }

    if (method === 'DELETE') {
        let input = {};
        try {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            input = JSON.parse(Buffer.concat(chunks).toString()) || {};
        } catch { input = {}; }

        if (!input.id) {
            return res.status(422).json({ ok: false, message: 'Missing booking id.' });
        }

        const result = await supabaseRequest('DELETE', 'farm_visit_bookings?id=eq.' + encodeURIComponent(input.id));
        if (!result.ok && result.fallback) {
            let bookings = loadFallbackBookings();
            bookings = bookings.filter(b => b.id !== input.id);
            saveFallbackBookings(bookings);
            return res.status(200).json({ ok: true, message: 'Booking deleted successfully.' });
        }

        if (!result.ok) {
            return res.status(result.status || 500).json({ ok: false, message: 'Booking delete failed', details: result });
        }

        return res.status(200).json({ ok: true, message: 'Booking deleted successfully.' });
    }

    return res.status(405).json({ ok: false, message: 'Method not allowed.' });
};
