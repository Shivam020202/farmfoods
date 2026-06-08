<?php
require_once __DIR__ . '/config.php';

function fetchBookings(): array {
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
    <header class="rounded-[28px] bg-[linear-gradient(120deg,#1c321d_0%,#3f6b3a_65%,#6d8c52_100%)] p-6 text-white shadow-soft">
      <p class="text-xs uppercase tracking-[0.3em] text-[#edf5e2]">Farm Made Foods CMS</p>
      <h1 class="mt-3 text-3xl font-semibold">Booking dashboard</h1>
      <p class="mt-2 max-w-2xl text-white/80">Review submitted farm visit bookings, update status, and manage follow-up notes from one place.</p>
    </header>

    <section class="grid gap-6 lg:grid-cols-[1fr_360px]">
      <article class="rounded-[28px] bg-white p-6 shadow-soft">
        <div class="flex items-center justify-between gap-4">
          <div>
            <p class="text-xs uppercase tracking-[0.3em] text-[#3f6b3a]">Bookings</p>
            <h2 class="mt-2 text-2xl font-semibold">Submitted visits</h2>
          </div>
          <a href="index.html" class="rounded-full bg-[#3f6b3a] px-4 py-2 text-sm text-white">Back to site</a>
        </div>

        <?php if (!$bookings['ok']) : ?>
          <div class="mt-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900"><?= htmlspecialchars($bookings['message']) ?></div>
        <?php else : ?>
          <div class="mt-6 overflow-x-auto">
            <table class="min-w-full divide-y divide-[#edf2ea] text-sm">
              <thead class="bg-[#f7f3ec] text-left text-[#3e5941]">
                <tr>
                  <th class="px-3 py-3">Lead</th>
                  <th class="px-3 py-3">Phone</th>
                  <th class="px-3 py-3">Date</th>
                  <th class="px-3 py-3">Slot</th>
                  <th class="px-3 py-3">Status</th>
                  <th class="px-3 py-3">Notes</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[#edf2ea]">
                <?php foreach ($bookings['data'] as $booking) : ?>
                  <tr class="align-top">
                    <td class="px-3 py-4">
                      <strong><?= htmlspecialchars($booking['lead_name'] ?? '') ?></strong><br />
                      <span class="text-xs text-[#5b6b5c]">Guests: <?= (int)($booking['attendee_count'] ?? 0) ?></span>
                    </td>
                    <td class="px-3 py-4 text-[#4b5b4d]"><?= htmlspecialchars($booking['phone'] ?? '') ?></td>
                    <td class="px-3 py-4 text-[#4b5b4d]"><?= htmlspecialchars($booking['visit_date'] ?? '') ?></td>
                    <td class="px-3 py-4 text-[#4b5b4d]"><?= htmlspecialchars($booking['slot_label'] ?? '') ?></td>
                    <td class="px-3 py-4">
                      <form class="space-y-2" onsubmit="return updateBooking(event, '<?= addslashes($booking['id']) ?>')">
                        <select id="status-<?= htmlspecialchars($booking['id']) ?>" class="w-full rounded-xl border border-[#dfe7df] bg-[#faf9f6] px-3 py-2 text-sm">
                          <?php foreach (['pending','confirmed','completed','cancelled'] as $status) : ?>
                            <option value="<?= $status ?>" <?= (($booking['status'] ?? 'pending') === $status ? 'selected' : '') ?>><?= ucfirst($status) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <textarea id="note-<?= htmlspecialchars($booking['id']) ?>" rows="2" class="w-full rounded-xl border border-[#dfe7df] bg-[#faf9f6] px-3 py-2 text-sm" placeholder="Admin note"><?= htmlspecialchars($booking['admin_note'] ?? '') ?></textarea>
                        <button class="rounded-full bg-[#3f6b3a] px-3 py-2 text-xs font-semibold text-white">Save</button>
                      </form>
                    </td>
                    <td class="px-3 py-4 text-[#4b5b4d] text-sm"><?= htmlspecialchars($booking['feedback_message'] ?? '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
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
    async function updateBooking(event, bookingId) {
      event.preventDefault();
      const status = document.getElementById('status-' + bookingId).value;
      const admin_note = document.getElementById('note-' + bookingId).value;
      const response = await fetch('api/bookings.php', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: bookingId, status, admin_note })
      });
      const result = await response.json();
      document.getElementById('saveMessage').textContent = result.ok ? 'Booking updated successfully.' : (result.message || 'Update failed.');
      return false;
    }
  </script>
</body>
</html>
