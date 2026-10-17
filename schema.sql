SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS dusun (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rw_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dusun_id BIGINT UNSIGNED NULL,
  number_code VARCHAR(10) NOT NULL,
  label VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rw_dusun_code (dusun_id, number_code),
  CONSTRAINT fk_rw_dusun FOREIGN KEY (dusun_id) REFERENCES dusun(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rt_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rw_id BIGINT UNSIGNED NOT NULL,
  number_code VARCHAR(10) NOT NULL,
  label VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rt_rw_code (rw_id, number_code),
  CONSTRAINT fk_rt_rw FOREIGN KEY (rw_id) REFERENCES rw_units(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(190) NULL UNIQUE,
  full_name VARCHAR(160) NOT NULL,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('warga','rt','rw','petugas','admin_desa','kepala_desa') NOT NULL DEFAULT 'warga',
  rt_id BIGINT UNSIGNED NULL,
  rw_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_role_active (role, is_active),
  KEY idx_users_rt (rt_id, is_active),
  CONSTRAINT fk_users_rt FOREIGN KEY (rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_rw FOREIGN KEY (rw_id) REFERENCES rw_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS family_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_number VARCHAR(32) NOT NULL,
  head_resident_id BIGINT UNSIGNED NULL,
  rt_id BIGINT UNSIGNED NOT NULL,
  address TEXT NOT NULL,
  status ENUM('draft','pending_review','active','moved','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_family_rt_status (rt_id, status),
  KEY idx_family_number (family_number),
  CONSTRAINT fk_family_rt FOREIGN KEY (rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_family_creator FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_family_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS residents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family_id BIGINT UNSIGNED NOT NULL,
  rt_id BIGINT UNSIGNED NOT NULL,
  nik CHAR(16) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  birth_date DATE NULL,
  gender ENUM('L','P','unknown') NOT NULL DEFAULT 'unknown',
  relationship_to_head VARCHAR(60) NULL,
  phone VARCHAR(30) NULL,
  resident_status ENUM('draft','pending_review','active','moved','deceased','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resident_nik (nik),
  KEY idx_resident_family (family_id),
  KEY idx_resident_rt_status (rt_id, resident_status),
  KEY idx_resident_name (full_name),
  CONSTRAINT fk_resident_family FOREIGN KEY (family_id) REFERENCES family_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_resident_rt FOREIGN KEY (rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_resident_creator FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_resident_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE family_records
  ADD CONSTRAINT fk_family_head_resident FOREIGN KEY (head_resident_id) REFERENCES residents(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS resident_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resident_id BIGINT UNSIGNED NULL,
  family_id BIGINT UNSIGNED NULL,
  rt_id BIGINT UNSIGNED NOT NULL,
  document_type ENUM('ktp','kk','akta','surat_pendukung','lainnya') NOT NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  original_name VARCHAR(190) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  status ENUM('pending','approved','revision_requested','rejected','archived') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(1000) NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  supersedes_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docs_rt_status (rt_id, status),
  KEY idx_docs_resident (resident_id, document_type),
  KEY idx_docs_family (family_id, document_type),
  CONSTRAINT fk_docs_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_docs_family FOREIGN KEY (family_id) REFERENCES family_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_docs_rt FOREIGN KEY (rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_docs_uploader FOREIGN KEY (uploaded_by) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_docs_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_docs_supersedes FOREIGN KEY (supersedes_id) REFERENCES resident_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resident_change_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resident_id BIGINT UNSIGNED NULL,
  family_id BIGINT UNSIGNED NULL,
  source_rt_id BIGINT UNSIGNED NOT NULL,
  target_rt_id BIGINT UNSIGNED NULL,
  change_type ENUM('add_resident','update','move_rt','split_family','deceased','archive','merge_review') NOT NULL,
  proposed_data JSON NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('draft','submitted','in_review','revision_requested','approved','rejected') NOT NULL DEFAULT 'draft',
  review_note VARCHAR(1000) NULL,
  submitted_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_changes_status (status, created_at),
  KEY idx_changes_rt (source_rt_id, status),
  CONSTRAINT fk_changes_resident FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_changes_family FOREIGN KEY (family_id) REFERENCES family_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_changes_source_rt FOREIGN KEY (source_rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_changes_target_rt FOREIGN KEY (target_rt_id) REFERENCES rt_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_changes_submitter FOREIGN KEY (submitted_by) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_changes_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL UNIQUE,
  description TEXT NULL,
  requirements JSON NULL,
  estimated_days SMALLINT UNSIGNED NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_code VARCHAR(32) NOT NULL UNIQUE,
  service_type_id BIGINT UNSIGNED NOT NULL,
  applicant_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(190) NOT NULL,
  request_data JSON NOT NULL,
  status ENUM('submitted','in_review','revision_requested','approved','rejected','ready','completed','cancelled') NOT NULL DEFAULT 'submitted',
  public_status_note VARCHAR(500) NULL,
  internal_note TEXT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_requests_status (status, created_at),
  KEY idx_requests_applicant (applicant_id, created_at),
  CONSTRAINT fk_request_service_type FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_request_applicant FOREIGN KEY (applicant_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_request_assignee FOREIGN KEY (assigned_to) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_request_approver FOREIGN KEY (approved_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_request_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  original_name VARCHAR(190) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_request_files_request (request_id),
  CONSTRAINT fk_request_file_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_request_file_uploader FOREIGN KEY (uploaded_by) REFERENCES app_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_code VARCHAR(32) NOT NULL UNIQUE,
  reporter_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'umum',
  description TEXT NOT NULL,
  status ENUM('received','in_review','in_progress','waiting_reporter','resolved','rejected') NOT NULL DEFAULT 'received',
  public_response TEXT NULL,
  internal_note TEXT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_complaints_status (status, created_at),
  KEY idx_complaints_reporter (reporter_id, created_at),
  CONSTRAINT fk_complaint_reporter FOREIGN KEY (reporter_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_complaint_assignee FOREIGN KEY (assigned_to) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(220) NOT NULL,
  slug VARCHAR(240) NOT NULL UNIQUE,
  category ENUM('kegiatan','pengumuman','program','berita') NOT NULL DEFAULT 'berita',
  excerpt VARCHAR(500) NULL,
  body MEDIUMTEXT NOT NULL,
  image_key VARCHAR(255) NULL,
  alt_text VARCHAR(255) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  expire_at DATETIME NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_news_publish (status, publish_at, expire_at),
  CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES app_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_content (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_key VARCHAR(120) NOT NULL UNIQUE,
  content_value MEDIUMTEXT NOT NULL,
  value_type ENUM('text','html','image','json') NOT NULL DEFAULT 'text',
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_site_content_editor FOREIGN KEY (updated_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_content_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_id BIGINT UNSIGNED NOT NULL,
  content_value MEDIUMTEXT NOT NULL,
  editor_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_content_versions (content_id, created_at),
  CONSTRAINT fk_content_version_content FOREIGN KEY (content_id) REFERENCES site_content(id) ON DELETE RESTRICT,
  CONSTRAINT fk_content_version_editor FOREIGN KEY (editor_id) REFERENCES app_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_content_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_key VARCHAR(120) NOT NULL,
  content_value MEDIUMTEXT NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  editor_id BIGINT UNSIGNED NOT NULL,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_site_revisions_key_status (content_key, status, created_at),
  CONSTRAINT fk_site_revision_editor FOREIGN KEY (editor_id) REFERENCES app_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id VARCHAR(80) NULL,
  before_data JSON NULL,
  after_data JSON NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor (actor_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO service_types (name, slug, description, requirements, estimated_days, is_published, sort_order)
VALUES
 ('Surat Keterangan Domisili', 'surat-keterangan-domisili', 'Permohonan surat keterangan domisili.', JSON_ARRAY('KTP','Kartu Keluarga'), 3, 1, 10),
 ('Surat Pengantar', 'surat-pengantar', 'Permohonan surat pengantar dari desa.', JSON_ARRAY('KTP','Kartu Keluarga'), 3, 1, 20),
 ('Surat Keterangan Usaha', 'surat-keterangan-usaha', 'Permohonan surat keterangan usaha.', JSON_ARRAY('KTP','Kartu Keluarga','Data usaha'), 5, 1, 30);
