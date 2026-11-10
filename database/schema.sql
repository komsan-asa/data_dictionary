-- ============================================================
--  db_data_dictionary : ฐานข้อมูลเก็บ Data Dictionary แยกตามโปรเจ็ค
--  รองรับ MySQL 5.7+ / MariaDB 10.2+
--  วิธีติดตั้ง:  mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS db_data_dictionary
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE db_data_dictionary;

-- ผู้ใช้หน้าเว็บ
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(100) NULL,
  last_seen_at  DATETIME     NULL COMMENT 'เวลาที่หน้าเว็บรายงานตัวล่าสุด ใช้แสดงผู้ใช้ออนไลน์',
  last_seen_project VARCHAR(50) NULL COMMENT 'รหัสโปรเจ็คที่กำลังเปิดอยู่',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ผู้ใช้งานหน้าเว็บ Data Dictionary';

-- โปรเจ็ค (1 โปรเจ็ค = 1 ฐานข้อมูลต้นทาง) พร้อมค่า connection
CREATE TABLE IF NOT EXISTS projects (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(50)  NOT NULL COMMENT 'รหัสโปรเจ็ค ใช้ใน URL และ API',
  name              VARCHAR(200) NOT NULL,
  description       TEXT         NULL,
  api_key_hash      CHAR(64)     NULL COMMENT 'SHA-256 ของ API key (ไม่เก็บ key จริง)',
  api_key_hint      VARCHAR(12)  NULL COMMENT 'ตัวอักษรต้นของ key ไว้ให้จำได้',
  conn_driver       VARCHAR(10)  NULL COMMENT 'mysql | pgsql',
  conn_host         VARCHAR(255) NULL,
  conn_port         INT UNSIGNED NULL,
  conn_database     VARCHAR(128) NULL,
  conn_schema       VARCHAR(128) NULL COMMENT 'เฉพาะ PostgreSQL (ค่าเริ่มต้น public)',
  conn_username     VARCHAR(128) NULL,
  conn_password_enc TEXT         NULL COMMENT 'รหัสผ่านเข้ารหัส AES-256-GCM',
  conn_charset      VARCHAR(20)  NULL,
  last_sync_at      DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_code (code),
  KEY ix_projects_api_key (api_key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='โปรเจ็คและค่าเชื่อมต่อฐานข้อมูลต้นทาง';

-- ตาราง / วิว ของแต่ละโปรเจ็ค
CREATE TABLE IF NOT EXISTS dd_tables (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  table_name      VARCHAR(128) NOT NULL,
  table_type      VARCHAR(20)  NOT NULL DEFAULT 'BASE TABLE' COMMENT 'BASE TABLE | VIEW',
  engine          VARCHAR(40)  NULL,
  table_collation VARCHAR(64)  NULL,
  row_estimate    BIGINT       NULL COMMENT 'จำนวนแถวโดยประมาณ ณ ตอนซิงค์',
  db_comment      TEXT         NULL COMMENT 'comment เดิมในฐานข้อมูลต้นทาง',
  description     TEXT         NULL COMMENT 'คำอธิบายความหมายของตาราง (ซิงค์ไม่เขียนทับ)',
  notes           TEXT         NULL,
  module          VARCHAR(100) NULL COMMENT 'กลุ่มงาน/โมดูล เช่น OPD, IPD, ห้องยา',
  source          VARCHAR(10)  NOT NULL DEFAULT 'sync' COMMENT 'sync | import | manual',
  is_dropped      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = ไม่พบในฐานข้อมูลต้นทางแล้ว',
  described_by    VARCHAR(100) NULL,
  described_at    DATETIME     NULL,
  last_synced_at  DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tables_project_name (project_id, table_name),
  KEY ix_tables_project_dropped (project_id, is_dropped),
  CONSTRAINT fk_tables_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ตารางและวิวของแต่ละโปรเจ็ค';

-- คอลัมน์ พร้อมคำอธิบายความหมาย
CREATE TABLE IF NOT EXISTS dd_columns (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_id         INT UNSIGNED NOT NULL,
  project_id       INT UNSIGNED NOT NULL,
  column_name      VARCHAR(128) NOT NULL,
  ordinal_position INT          NOT NULL DEFAULT 0,
  data_type        VARCHAR(64)  NULL COMMENT 'ชนิดข้อมูลแบบสั้น เช่น varchar',
  column_type      TEXT         NULL COMMENT 'ชนิดข้อมูลเต็ม เช่น varchar(9)',
  is_nullable      TINYINT(1)   NOT NULL DEFAULT 1,
  column_default   TEXT         NULL,
  column_key       VARCHAR(10)  NULL COMMENT 'PRI | UNI | MUL',
  extra            VARCHAR(255) NULL,
  db_comment       TEXT         NULL COMMENT 'comment เดิมในฐานข้อมูลต้นทาง',
  description      TEXT         NULL COMMENT 'ความหมายของคอลัมน์ (ซิงค์ไม่เขียนทับ)',
  allowed_values   TEXT         NULL COMMENT 'ค่าที่เป็นไปได้ / รหัสและความหมาย',
  ref_table        VARCHAR(128) NULL COMMENT 'ตารางที่อ้างอิงถึง',
  ref_column       VARCHAR(128) NULL,
  notes            TEXT         NULL,
  sample_json      MEDIUMTEXT   NULL COMMENT 'ตัวอย่างข้อมูลจากฐานข้อมูลต้นทาง (JSON)',
  suggest_json     TEXT         NULL COMMENT 'ข้อเสนอที่ระบบเดา รอคนพิจารณา (JSON)',
  sampled_at       DATETIME     NULL COMMENT 'เวลาที่อ่านตัวอย่างข้อมูลล่าสุด',
  source           VARCHAR(10)  NOT NULL DEFAULT 'sync',
  is_dropped       TINYINT(1)   NOT NULL DEFAULT 0,
  described_by     VARCHAR(100) NULL,
  described_at     DATETIME     NULL,
  last_synced_at   DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_columns_table_name (table_id, column_name),
  KEY ix_columns_project_name (project_id, column_name),
  CONSTRAINT fk_columns_table   FOREIGN KEY (table_id)   REFERENCES dd_tables (id) ON DELETE CASCADE,
  CONSTRAINT fk_columns_project FOREIGN KEY (project_id) REFERENCES projects (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='คอลัมน์ของแต่ละตาราง พร้อมคำอธิบายความหมาย';

-- index ของแต่ละตาราง (สร้างใหม่ทุกครั้งที่ซิงค์)
CREATE TABLE IF NOT EXISTS dd_indexes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_id    INT UNSIGNED NOT NULL,
  index_name  VARCHAR(128) NOT NULL,
  is_unique   TINYINT(1)   NOT NULL DEFAULT 0,
  column_list TEXT         NOT NULL COMMENT 'ชื่อคอลัมน์คั่นด้วย , ตามลำดับใน index',
  PRIMARY KEY (id),
  KEY ix_indexes_table (table_id),
  CONSTRAINT fk_indexes_table FOREIGN KEY (table_id) REFERENCES dd_tables (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='index ของแต่ละตาราง';

-- ประวัติการซิงค์และการนำเข้า
CREATE TABLE IF NOT EXISTS sync_logs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  kind            VARCHAR(10)  NOT NULL COMMENT 'sync | import | guess | clone',
  actor           VARCHAR(100) NULL COMMENT 'ชื่อผู้ใช้ หรือ api-key',
  status          VARCHAR(10)  NOT NULL COMMENT 'ok | error',
  tables_total    INT NOT NULL DEFAULT 0,
  tables_new      INT NOT NULL DEFAULT 0,
  columns_total   INT NOT NULL DEFAULT 0,
  columns_new     INT NOT NULL DEFAULT 0,
  columns_dropped INT NOT NULL DEFAULT 0,
  message         TEXT NULL,
  started_at      DATETIME NOT NULL,
  finished_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_logs_project (project_id, id),
  CONSTRAINT fk_logs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการซิงค์โครงสร้างและการนำเข้าข้อมูล';
