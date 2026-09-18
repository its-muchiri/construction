-- construction.co.ke — PostgreSQL schema (Neon, used on Vercel).
-- Mirrors schema.sql (MySQL/MariaDB, used for local development) exactly in
-- shape; only dialect differs. See rider-co-ke's schema.postgres.sql header
-- for the full list of translation rules, and
-- planning/00-portfolio/ui-implementation-plan.md for why this file exists.

-- ============================================================
-- SHARED CORE TABLES — identical shape across all five platforms
-- ============================================================

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    national_id_number VARCHAR(20) NULL,
    account_type VARCHAR(20) NOT NULL CHECK (account_type IN ('customer', 'provider', 'admin')),
    status VARCHAR(30) NOT NULL DEFAULT 'pending_verification' CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE roles (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE permissions (
    id BIGSERIAL PRIMARY KEY,
    "key" VARCHAR(150) NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id BIGINT NOT NULL REFERENCES users(id),
    role_id BIGINT NOT NULL REFERENCES roles(id),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    booking_id BIGINT NULL,
    order_id BIGINT NULL,
    -- construction.co.ke-specific extension (not part of the portfolio's
    -- shared payments shape): links a charge to the specific
    -- project_milestones tranche it funds, since unlike the other
    -- platforms' single-payment-per-booking model, one construction booking
    -- can have several milestone charges. See src/Core/Escrow.php. FK added
    -- below, after project_milestones exists.
    milestone_id BIGINT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('charge', 'payout', 'refund', 'commission')),
    method VARCHAR(20) NOT NULL CHECK (method IN ('mpesa_stk', 'mpesa_c2b', 'mpesa_b2c', 'card')),
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    external_reference VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed', 'reversed')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_payments_external_reference ON payments(external_reference);

CREATE TABLE payment_callbacks_log (
    id BIGSERIAL PRIMARY KEY,
    checkout_request_id VARCHAR(100) NOT NULL UNIQUE,
    raw_payload JSON NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE escrow_transactions (
    id BIGSERIAL PRIMARY KEY,
    payment_id BIGINT NOT NULL REFERENCES payments(id),
    booking_id BIGINT NOT NULL,
    held_amount DECIMAL(12,2) NOT NULL,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    release_condition VARCHAR(30) NOT NULL CHECK (release_condition IN ('auto_timeout', 'customer_confirmation', 'admin_release', 'dispute_resolution')),
    release_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'released', 'partially_released', 'refunded'))
);

CREATE TABLE commission_rules (
    id BIGSERIAL PRIMARY KEY,
    platform VARCHAR(20) NOT NULL DEFAULT 'construction' CHECK (platform IN ('laundry', 'rider', 'construction', 'solar', 'event')),
    category VARCHAR(100) NOT NULL,
    commission_type VARCHAR(20) NOT NULL CHECK (commission_type IN ('percentage', 'flat_fee', 'tiered')),
    value DECIMAL(10,2) NOT NULL,
    min_transaction_value DECIMAL(12,2) NULL,
    max_transaction_value DECIMAL(12,2) NULL,
    effective_from TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to TIMESTAMP NULL
);

CREATE TABLE kyc_documents (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    document_type VARCHAR(30) NOT NULL CHECK (document_type IN ('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address', 'proof_of_ownership')),
    file_reference VARCHAR(500) NOT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending', 'verified', 'rejected', 'expired')),
    verified_by BIGINT NULL REFERENCES users(id),
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL
);

CREATE TABLE reviews (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL REFERENCES users(id),
    reviewee_id BIGINT NOT NULL REFERENCES users(id),
    rating SMALLINT NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- category values for this platform: equipment_damage, work_quality, non_completion, payment_delay, other
CREATE TABLE disputes (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL,
    raised_by BIGINT NOT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    evidence_urls JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'under_review', 'resolved_refund', 'resolved_partial', 'resolved_no_action', 'escalated')),
    resolved_by BIGINT NULL REFERENCES users(id),
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL
);

CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_id BIGINT NULL REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT NOT NULL,
    before_state JSON NULL,
    after_state JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PLATFORM-SPECIFIC TABLES — construction.co.ke
-- ============================================================

CREATE TABLE construction_bookings (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    provider_id BIGINT NULL REFERENCES users(id),
    booking_type VARCHAR(20) NOT NULL CHECK (booking_type IN ('equipment_rental', 'labor_contract')),
    category VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open_for_quotes' CHECK (status IN ('open_for_quotes', 'quote_accepted', 'mobilized', 'in_progress', 'awaiting_final_signoff', 'inspection_window', 'completed', 'cancelled', 'disputed')),
    location_address VARCHAR(500) NOT NULL,
    location_lat DECIMAL(10,7) NOT NULL,
    location_lng DECIMAL(10,7) NOT NULL,
    rental_start_date DATE NULL,
    rental_end_date DATE NULL,
    project_start_date DATE NULL,
    project_end_date DATE NULL,
    total_contract_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    retention_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    inspection_window_ends_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE project_quotes (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES construction_bookings(id),
    provider_id BIGINT NOT NULL REFERENCES users(id),
    quoted_amount DECIMAL(14,2) NOT NULL,
    proposed_start_date DATE NOT NULL,
    conditions TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'accepted', 'rejected', 'withdrawn')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE project_milestones (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES construction_bookings(id),
    sequence_number INT NOT NULL,
    description VARCHAR(500) NOT NULL,
    percentage_of_total DECIMAL(5,2) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'provider_requested_signoff', 'customer_confirmed', 'disputed', 'released')),
    escrow_transaction_id BIGINT NULL REFERENCES escrow_transactions(id),
    confirmed_at TIMESTAMP NULL
);

CREATE TABLE equipment_listings (
    id BIGSERIAL PRIMARY KEY,
    provider_id BIGINT NOT NULL REFERENCES users(id),
    equipment_category VARCHAR(100) NOT NULL,
    make_model VARCHAR(255) NULL,
    year_of_manufacture INT NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    weekly_rate DECIMAL(10,2) NOT NULL,
    ownership_kyc_document_id BIGINT NOT NULL REFERENCES kyc_documents(id),
    insurance_kyc_document_id BIGINT NULL REFERENCES kyc_documents(id),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'rented', 'maintenance', 'inactive'))
);

CREATE TABLE equipment_condition_reports (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES construction_bookings(id),
    equipment_listing_id BIGINT NOT NULL REFERENCES equipment_listings(id),
    report_type VARCHAR(20) NOT NULL CHECK (report_type IN ('handover', 'return')),
    submitted_by BIGINT NOT NULL REFERENCES users(id),
    photo_urls JSON NOT NULL,
    video_url VARCHAR(500) NULL,
    condition_notes TEXT NOT NULL,
    meter_reading_hours DECIMAL(8,2) NULL,
    independently_verified BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE crew_listings (
    id BIGSERIAL PRIMARY KEY,
    provider_id BIGINT NOT NULL REFERENCES users(id),
    trade VARCHAR(100) NOT NULL,
    team_size INT NOT NULL,
    day_rate DECIMAL(10,2) NULL,
    certification_kyc_document_id BIGINT NULL REFERENCES kyc_documents(id),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'engaged', 'inactive'))
);

ALTER TABLE disputes ADD CONSTRAINT fk_disputes_booking FOREIGN KEY (booking_id) REFERENCES construction_bookings(id);
ALTER TABLE reviews ADD CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES construction_bookings(id);
ALTER TABLE escrow_transactions ADD CONSTRAINT fk_escrow_booking FOREIGN KEY (booking_id) REFERENCES construction_bookings(id);
ALTER TABLE payments ADD CONSTRAINT fk_payments_milestone FOREIGN KEY (milestone_id) REFERENCES project_milestones(id);

-- ============================================================
-- E-COMMERCE STORE — shared shape across all five platforms
-- ============================================================

CREATE TABLE store_products (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NULL REFERENCES users(id),
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    image_urls JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'out_of_stock', 'inactive')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_orders (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    payment_id BIGINT NULL REFERENCES payments(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'fulfilled', 'cancelled')),
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE store_order_items (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES store_orders(id),
    product_id BIGINT NOT NULL REFERENCES store_products(id),
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL
);

ALTER TABLE payments ADD CONSTRAINT fk_payments_store_order FOREIGN KEY (order_id) REFERENCES store_orders(id);
