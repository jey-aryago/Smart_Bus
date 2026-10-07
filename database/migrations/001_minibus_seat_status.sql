ALTER TABLE minibuses
    ADD COLUMN seat_status ENUM('unknown', 'available', 'full') NOT NULL DEFAULT 'unknown'
    AFTER capacity;
