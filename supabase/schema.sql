create table if not exists public.farm_visit_bookings (
  id uuid primary key default gen_random_uuid(),
  lead_name text not null,
  phone text not null,
  email text,
  visit_date date not null,
  slot_label text not null,
  slot_time text,
  attendee_count integer not null default 1,
  attendee_details jsonb default '[]'::jsonb,
  status text not null default 'pending',
  feedback_message text,
  admin_note text,
  source text default 'farmmade-frontend',
  verified_otp boolean default false,
  created_at timestamptz default now()
);

alter table public.farm_visit_bookings enable row level security;

create policy if not exists "Allow service role full access" on public.farm_visit_bookings
for all using (true) with check (true);

create policy if not exists "Allow anon read access" on public.farm_visit_bookings
for select using (true);
