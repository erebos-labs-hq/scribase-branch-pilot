create table public.bookmarks (
    id uuid primary key default gen_random_uuid(),
    owner uuid not null references auth.users(id),
    url text not null,
    created_at timestamptz not null default now()
);
create index on public.bookmarks (owner);
alter table public.bookmarks enable row level security;
create policy bookmarks_owner on public.bookmarks for all
    using (owner = auth.uid()) with check (owner = auth.uid());
