<?php
require_once __DIR__ . '/config.php';

function fetchBookings(): array
{
    $cfg = supabaseConfig();
    if ($cfg['url'] === '' || $cfg['service_role_key'] === '') {
        return ['ok' => false, 'message' => 'Supabase variables missing.'];
    }

    $url = rtrim($cfg['url'], '/') . '/rest/v1/farm_visit_bookings?select=id,lead_name,phone,email,visit_date,slot_label,attendee_count,status,feedback_message,admin_note,created_at&order=created_at.desc';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $cfg['anon_key'],
        'Authorization: Bearer ' . $cfg['service_role_key'],
        'Content-Type: application/json',
    ]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'message' => 'Unable to fetch bookings', 'http' => $http, 'raw' => $response];
    }

    return ['ok' => true, 'data' => json_decode($response, true) ?: []];
}

$bookings = fetchBookings();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Farm Made Foods CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-[#fffaf4] text-[#2f3a30]">
    <main class="mx-auto flex min-h-screen max-w-7xl flex-col gap-6 p-6 lg:p-10">
        <header
            class="rounded-[28px] bg-[linear-gradient(120deg,#1c321d_0%,#3f6b3a_65%,#6d8c52_100%)] p-6 text-white shadow-soft">
            <p class="text-xs uppercase tracking-[0.3em] text-[#edf5e2]">Farm Made Foods CMS</p>
            <h1 class="mt-3 text-3xl font-semibold">Booking dashboard</h1>
            <p class="mt-2 max-w-2xl text-white/80">Review submitted farm visit bookings, update status, and manage
                follow-up notes from one place.</p>
        </header>

        <section class="grid gap-6 lg:grid-cols-[1fr_360px]">
            <article class="rounded-[28px] bg-white p-6 shadow-soft">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-[#3f6b3a]">Bookings</p>
                        <h2 class="mt-2 text-2xl font-semibold">Submitted visits</h2>
                        <p class="mt-1 text-sm text-[#4b5b4d]">Use the filters below to review and manage the latest farm visits.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="refreshBtn" class="rounded-full border border-[#dfe7df] bg-white px-4 py-2 text-sm text-[#345236]">Refresh</button>
                        <a href="index.html" class="rounded-full bg-[#3f6b3a] px-4 py-2 text-sm text-white">Back to site</a>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-4">
                    <div class="rounded-2xl bg-[#f6f3eb] p-4"><p class="text-xs uppercase tracking-[0.25em] text-[#3f6b3a]">Total</p><p id="statTotal" class="mt-2 text-3xl font-semibold">0</p></div>
                    <div class="rounded-2xl bg-[#f6f3eb] p-4"><p class="text-xs uppercase tracking-[0.25em] text-[#3f6b3a]">Pending</p><p id="statPending" class="mt-2 text-3xl font-semibold">0</p></div>
                    <div class="rounded-2xl bg-[#f6f3eb] p-4"><p class="text-xs uppercase tracking-[0.25em] text-[#3f6b3a]">Confirmed</p><p id="statConfirmed" class="mt-2 text-3xl font-semibold">0</p></div>
                    <div class="rounded-2xl bg-[#f6f3eb] p-4"><p class="text-xs uppercase tracking-[0.25em] text-[#3f6b3a]">Completed</p><p id="statCompleted" class="mt-2 text-3xl font-semibold">0</p></div>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <input id="searchInput" type="search" placeholder="Search by name or phone" class="w-full rounded-full border border-[#dfe7df] bg-[#faf9f6] px-4 py-2.5 text-sm md:max-w-xs" />
                    <select id="statusFilter" class="rounded-full border border-[#dfe7df] bg-[#faf9f6] px-4 py-2.5 text-sm">
                        <option value="all">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div id="dashboardAlert" class="mt-4 hidden rounded-2xl bg-amber-50 p-4 text-sm text-amber-900"></div>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-[#edf2ea] text-sm">
                        <thead class="bg-[#f7f3ec] text-left text-[#3e5941]">
                            <tr>
                                <th class="px-3 py-3">Lead</th>
                                <th class="px-3 py-3">Phone</th>
                                <th class="px-3 py-3">Date</th>
                                <th class="px-3 py-3">Slot</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="bookingTableBody" class="divide-y divide-[#edf2ea]"></tbody>
                    </table>
                </div>
            </article>

            <aside class="rounded-[28px] bg-white p-6 shadow-soft">
                <p class="text-xs uppercase tracking-[0.3em] text-[#3f6b3a]">Quick actions</p>
                <h3 class="mt-2 text-xl font-semibold">Manage the booking flow</h3>
                <ul class="mt-4 space-y-3 text-sm text-[#4b5b4d]">
                    <li class="rounded-2xl bg-[#f7f3ec] p-4">Confirm pending visits, update status, and add admin notes in one click.</li>
                    <li class="rounded-2xl bg-[#f7f3ec] p-4">Review phone, slot and attendee count for each booking submission.</li>
                    <li class="rounded-2xl bg-[#f7f3ec] p-4">Use this panel as the lightweight CMS for farm visit management.</li>
                </ul>
                <div id="saveMessage" class="mt-6 rounded-2xl bg-[#edf5e2] p-4 text-sm text-[#345236]"></div>
            </aside>
        </section>
    </main>

    <script>
        const bookingTableBody = document.getElementById('bookingTableBody');
        const dashboardAlert = document.getElementById('dashboardAlert');
        const saveMessage = document.getElementById('saveMessage');
        const searchInput = document.getElementById('searchInput');
        const statusFilter = document.getElementById('statusFilter');

        function badgeClass(status) {
            return {
                pending: 'bg-amber-100 text-amber-800',
                confirmed: 'bg-emerald-100 text-emerald-800',
                completed: 'bg-sky-100 text-sky-800',
                cancelled: 'bg-rose-100 text-rose-800'
            }[status] || 'bg-slate-100 text-slate-700';
        }

        function formatBooking(booking) {
            return `
                <tr class="align-top">
                    <td class="px-3 py-4">
                        <strong>${booking.lead_name || 'Unknown'}</strong><br />
                        <span class="text-xs text-[#5b6b5c]">Guests: ${booking.attendee_count || 0}</span>
                    </td>
                    <td class="px-3 py-4 text-[#4b5b4d]">${booking.phone || '—'}</td>
                    <td class="px-3 py-4 text-[#4b5b4d]">${booking.visit_date || '—'}</td>
                    <td class="px-3 py-4 text-[#4b5b4d]">${booking.slot_label || '—'}</td>
                    <td class="px-3 py-4">
                        <div class="space-y-2">
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ${badgeClass(booking.status)}">${(booking.status || 'pending').toUpperCase()}</span>
                            <select data-id="${booking.id}" class="statusSelect w-full rounded-xl border border-[#dfe7df] bg-[#faf9f6] px-3 py-2 text-sm">
                                ${['pending','confirmed','completed','cancelled'].map(status => `<option value="${status}" ${booking.status === status ? 'selected' : ''}>${status.charAt(0).toUpperCase()+status.slice(1)}</option>`).join('')}
                            </select>
                            <textarea data-note="${booking.id}" rows="2" class="w-full rounded-xl border border-[#dfe7df] bg-[#faf9f6] px-3 py-2 text-sm" placeholder="Admin note">${booking.admin_note || ''}</textarea>
                            <div class="flex gap-2">
                                <button data-save="${booking.id}" class="rounded-full bg-[#3f6b3a] px-3 py-2 text-xs font-semibold text-white">Save</button>
                                <button data-delete="${booking.id}" class="rounded-full bg-rose-100 px-3 py-2 text-xs font-semibold text-rose-700">Delete</button>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-4 text-[#4b5b4d] text-sm">${booking.feedback_message || 'No feedback note yet.'}</td>
                </tr>`;
        }

        async function loadBookings() {
            dashboardAlert.classList.add('hidden');
            saveMessage.textContent = '';
            try {
                const response = await fetch('api/bookings.php');
                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.message || 'Unable to fetch bookings.');
                }
                const bookings = result.body || [];
                const total = bookings.length;
                const pending = bookings.filter(b => b.status === 'pending').length;
                const confirmed = bookings.filter(b => b.status === 'confirmed').length;
                const completed = bookings.filter(b => b.status === 'completed').length;
                document.getElementById('statTotal').textContent = total;
                document.getElementById('statPending').textContent = pending;
                document.getElementById('statConfirmed').textContent = confirmed;
                document.getElementById('statCompleted').textContent = completed;

                const search = searchInput.value.toLowerCase();
                const filter = statusFilter.value;
                const filtered = bookings.filter(b => {
                    const text = `${b.lead_name || ''} ${b.phone || ''} ${b.slot_label || ''}`.toLowerCase();
                    const matchesSearch = text.includes(search);
                    const matchesStatus = filter === 'all' || b.status === filter;
                    return matchesSearch && matchesStatus;
                });

                bookingTableBody.innerHTML = filtered.map(formatBooking).join('');
            } catch (error) {
                dashboardAlert.textContent = error.message;
                dashboardAlert.classList.remove('hidden');
            }
        }

        async function updateBooking(bookingId) {
            const status = document.querySelector(`select[data-id="${bookingId}"]`).value;
            const admin_note = document.querySelector(`textarea[data-note="${bookingId}"]`).value;
            const response = await fetch('api/bookings.php', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: bookingId, status, admin_note })
            });
            const result = await response.json();
            saveMessage.textContent = result.ok ? 'Booking updated successfully.' : (result.message || 'Update failed.');
            loadBookings();
        }

        async function deleteBooking(bookingId) {
            if (!confirm('Delete this booking?')) return;
            const response = await fetch('api/bookings.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: bookingId })
            });
            const result = await response.json();
            saveMessage.textContent = result.ok ? 'Booking deleted.' : (result.message || 'Delete failed.');
            loadBookings();
        }

        document.getElementById('refreshBtn').addEventListener('click', loadBookings);
        searchInput.addEventListener('input', loadBookings);
        statusFilter.addEventListener('change', loadBookings);
        bookingTableBody.addEventListener('click', (event) => {
            const saveBtn = event.target.closest('[data-save]');
            const deleteBtn = event.target.closest('[data-delete]');
            if (saveBtn) updateBooking(saveBtn.getAttribute('data-save'));
            if (deleteBtn) deleteBooking(deleteBtn.getAttribute('data-delete'));
        });

        loadBookings();
    </script>
</body>

</html>