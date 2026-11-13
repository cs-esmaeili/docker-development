-- Runs once, only when postgres_data is still empty
-- (docker-entrypoint-initdb.d is skipped for existing volumes).

-- pgvector: vector column type + exact/approximate nearest-neighbour search
CREATE EXTENSION IF NOT EXISTS vector;

-- handy extras for AI / RAG workloads
CREATE EXTENSION IF NOT EXISTS pg_trgm;      -- fuzzy text search (hybrid retrieval)
CREATE EXTENSION IF NOT EXISTS unaccent;     -- accent-insensitive matching
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";  -- uuid_generate_v4()
CREATE EXTENSION IF NOT EXISTS pgcrypto;     -- gen_random_uuid()
