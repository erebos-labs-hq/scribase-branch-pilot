-- Looks fine, but anyone with the anon key could read every user's bookmarks: no row level security.
create table public.bookmarks (
    id uuid primary key default gen_random_uuid(),
    owner uuid not null references auth.users(id),
    url text not null,
    created_at timestamptz not null default now()
);
