-- A question/answer per language, kept once per entry like the core's own
-- pages. extension_id names which installed extension this entry belongs
-- to (its id, e.g. "freelancer"), so an operator running several
-- marketplace extensions one after another is never shown another one's
-- questions; NULL means "general", shown regardless of what is enabled.
-- There is no foreign key to the extension table on purpose: an entry
-- outlives whatever happens to the extension it was written for, and
-- simply stops showing while that extension is not enabled.

CREATE TABLE x_faq_item (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    extension_id VARCHAR(32) NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_x_faq_item_extension (extension_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_faq_item_translation (
    item_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    question VARCHAR(300) NOT NULL,
    answer MEDIUMTEXT NOT NULL,
    PRIMARY KEY (item_id, locale),
    CONSTRAINT fk_x_faq_item_translation FOREIGN KEY (item_id) REFERENCES x_faq_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
