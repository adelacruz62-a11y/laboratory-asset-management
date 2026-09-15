create type public.user_role as enum ('Administrator', 'Laboratory Staff', 'Requester / Viewer');
create type public.equipment_status as enum ('Available', 'Borrowed', 'Maintenance', 'Damaged');
create type public.borrowing_status as enum ('Pending', 'Approved', 'Rejected', 'Released', 'Returned', 'Overdue', 'Closed');

create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  name text not null,
  role public.user_role not null default 'Requester / Viewer'
);

create table public.equipment (
  id uuid primary key default gen_random_uuid(),
  code text unique not null,
  name text not null,
  status public.equipment_status not null default 'Available',
  location text not null,
  condition text not null default 'Good',
  created_at timestamptz not null default now()
);

create table public.borrowing_requests (
  id uuid primary key default gen_random_uuid(),
  equipment_id uuid not null references public.equipment(id),
  requester_id uuid not null references public.profiles(id),
  approved_by uuid references public.profiles(id),
  status public.borrowing_status not null default 'Pending',
  requested_days integer not null check (requested_days between 1 and 30),
  notes text,
  requested_on date not null default current_date,
  released_at timestamptz,
  returned_at timestamptz
);

create table public.maintenance_requests (
  id uuid primary key default gen_random_uuid(),
  equipment_id uuid not null references public.equipment(id),
  requester_id uuid not null references public.profiles(id),
  status text not null default 'Open',
  description text not null,
  created_at timestamptz not null default now()
);

create table public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id),
  action text not null,
  module text not null,
  record_id text not null,
  description text not null,
  created_at timestamptz not null default now()
);

create or replace function public.current_role()
returns public.user_role
language sql stable security definer set search_path = public
as $$ select role from public.profiles where id = auth.uid() $$;

create or replace function public.validate_borrowing_request()
returns trigger
language plpgsql security definer set search_path = public
as $$
declare
  equipment_state public.equipment_status;
  actor_role public.user_role;
begin
  select status into equipment_state from public.equipment where id = new.equipment_id;
  if tg_op = 'INSERT' and equipment_state <> 'Available' then
    raise exception 'BR-A4-01: only available equipment may be requested';
  end if;

  actor_role := public.current_role();
  if tg_op = 'UPDATE' then
    if new.status in ('Approved', 'Rejected') and actor_role <> 'Administrator' then
      raise exception 'BR-A4-03: only administrators may approve or reject requests';
    end if;
    if new.status = 'Released' and old.status <> 'Approved' then
      raise exception 'BR-A4-04: only approved requests may be released';
    end if;
    if new.status in ('Returned', 'Overdue') and old.status not in ('Released', 'Overdue') then
      raise exception 'Only released requests may be returned';
    end if;
    if new.status = 'Closed' and old.status <> 'Returned' then
      raise exception 'Only returned requests may be closed';
    end if;
    if new.status = 'Returned' and old.status = 'Returned' then
      raise exception 'BR-A4-08: returned transactions cannot be processed twice';
    end if;
  end if;
  return new;
end;
$$;

create or replace function public.sync_equipment_status()
returns trigger
language plpgsql security definer set search_path = public
as $$
begin
  if new.status = 'Released' then
    update public.equipment set status = 'Borrowed' where id = new.equipment_id;
  elsif new.status = 'Returned' then
    update public.equipment set status = 'Available' where id = new.equipment_id;
  end if;
  return new;
end;
$$;

create or replace function public.audit_borrowing_change()
returns trigger
language plpgsql security definer set search_path = public
as $$
begin
  if tg_op = 'INSERT' then
    insert into public.audit_logs (user_id, action, module, record_id, description)
    values (new.requester_id, 'SUBMITTED', 'Borrowing', new.id::text, 'Borrowing request submitted');
  elsif old.status is distinct from new.status then
    insert into public.audit_logs (user_id, action, module, record_id, description)
    values (coalesce(new.approved_by, auth.uid()), upper(new.status), 'Borrowing', new.id::text, 'Borrowing request changed to ' || new.status);
  end if;
  return new;
end;
$$;

create trigger borrowing_request_rules
before insert or update on public.borrowing_requests
for each row execute function public.validate_borrowing_request();

create trigger borrowing_equipment_sync
after update of status on public.borrowing_requests
for each row execute function public.sync_equipment_status();

create trigger borrowing_audit_trail
after insert or update on public.borrowing_requests
for each row execute function public.audit_borrowing_change();

alter table public.profiles enable row level security;
alter table public.equipment enable row level security;
alter table public.borrowing_requests enable row level security;
alter table public.maintenance_requests enable row level security;
alter table public.audit_logs enable row level security;

create policy "authenticated users can view equipment" on public.equipment for select to authenticated using (true);
create policy "admins manage equipment" on public.equipment for all to authenticated using (public.current_role() = 'Administrator') with check (public.current_role() = 'Administrator');
create policy "users view own profile" on public.profiles for select to authenticated using (id = auth.uid() or public.current_role() = 'Administrator');
create policy "admins manage profiles" on public.profiles for all to authenticated using (public.current_role() = 'Administrator') with check (public.current_role() = 'Administrator');
create policy "users create own requests" on public.borrowing_requests for insert to authenticated with check (requester_id = auth.uid());
create policy "users view own requests" on public.borrowing_requests for select to authenticated using (requester_id = auth.uid() or public.current_role() = 'Administrator' or public.current_role() = 'Laboratory Staff');
create policy "admins update requests" on public.borrowing_requests for update to authenticated using (public.current_role() = 'Administrator') with check (public.current_role() = 'Administrator');
create policy "staff process released requests" on public.borrowing_requests for update to authenticated using (public.current_role() = 'Laboratory Staff' and status in ('Released', 'Overdue')) with check (status in ('Returned', 'Closed'));
create policy "admins view audit logs" on public.audit_logs for select to authenticated using (public.current_role() = 'Administrator');
create policy "authenticated users append audit logs" on public.audit_logs for insert to authenticated with check (user_id = auth.uid());
create policy "staff and admins manage maintenance" on public.maintenance_requests for all to authenticated using (public.current_role() in ('Administrator', 'Laboratory Staff')) with check (requester_id = auth.uid() or public.current_role() = 'Administrator');