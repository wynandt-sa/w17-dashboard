CREATE DATABASE IF NOT EXISTS w17_ticketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE w17_ticketing;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE,
  password VARCHAR(255),
  email VARCHAR(100),
  role ENUM('admin','user') DEFAULT 'user',
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  date_of_birth DATE NULL,
  work_anniversary DATE NULL,
  active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) UNIQUE,
  name VARCHAR(100),
  address VARCHAR(255) NULL,
  manager VARCHAR(100) NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(100) NULL,
  active BOOLEAN DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_number VARCHAR(20) UNIQUE,
  subject VARCHAR(255),
  requester_email VARCHAR(100),
  priority ENUM('Low','Medium','High','Critical') DEFAULT 'Medium',
  status ENUM('New','Open','Pending','Resolved','Closed') DEFAULT 'New',
  description TEXT,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255),
  description TEXT,
  assignee INT,
  due_date DATE,
  status ENUM('pending','completed') DEFAULT 'pending',
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (assignee) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Admin user (admin/admin123) and demo user (user1/user123)
INSERT INTO users (username,password,email,role,first_name,last_name,active)
VALUES
('admin',  '$2y$10$9lR5k8nZqL5w1z5qX6mQhe7p2b3Eo2sZ8mV9iYk4pF1g0p5l1pQn2', 'admin@workshop17.com', 'admin', 'System','Administrator', 1),
('user1',  '$2y$10$yV2nK8sQ3sK4mG9tQv2Ygec8f8s3Q4JxqI9y3GJH8R.2p1n7R0CI6', 'user1@workshop17.com', 'user', 'John', 'Doe', 1);
/* Hashes correspond to admin123 / user123 */

CREATE TABLE IF NOT EXISTS counters (k VARCHAR(50) PRIMARY KEY, v INT NOT NULL);
INSERT IGNORE INTO counters (k, v) VALUES ('ticket', 0);
