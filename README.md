<div align="center">

# 🚀 Lite-S3

### S3-Compatible Object Storage for Shared Hosting

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](http://makeapullrequest.com)

*Finally, run your own S3 storage on any $5/month shared hosting!*

[Quick Start](#-quick-start) • [Features](#-features) • [Screenshots](#-screenshots) • [API Docs](#-api-usage) • [License](#-license)

</div>

---

## 🤔 What is this?

Ever wanted Amazon S3-like storage but don't want to pay AWS prices? Or maybe you're stuck on shared hosting (cPanel) and can't run MinIO or other S3 alternatives?

**Lite-S3** is a pure PHP implementation of S3-compatible object storage. It works on any hosting that supports PHP and MySQL — yes, even that cheap shared hosting you're already paying for!

## ✨ Features

| Feature | Description |
|---------|-------------|
| 🔌 **S3 Compatible** | Works with AWS SDK, rclone, boto3, s3cmd |
| 👥 **Multi-User** | Each user gets their own credentials |
| 🔐 **Permissions** | Read / Write / Admin per bucket |
| 📁 **Big Files** | Upload files up to 5GB with multipart |
| 📹 **Range Requests** | Video seeking and partial downloads |
| 🛡️ **Rate Limiting** | Configurable request limits per IP |
| 🔍 **Security Scan** | Blocks malicious file uploads |
| 🎨 **Admin Panel** | Beautiful web UI to manage everything |
| 💾 **Easy Backups** | Plain files + MySQL = simple backups |
| 🏠 **Shared Hosting** | Works on cPanel, DirectAdmin, Plesk |

## 📸 Screenshots

<div align="center">

### Dashboard
![Dashboard](docs/screenshots/dashboard.png)

### User Management
![Users](docs/screenshots/users.png)

### Bucket Management
![Buckets](docs/screenshots/buckets.png)

</div>

## 🚀 Quick Start

### 🐳 Docker (Fastest - Just 2 commands!)

```bash
git clone https://github.com/nityam2007/lite-s3.git
cd lite-s3
docker-compose up -d
```

That's it! Everything auto-configures:
- ✅ MySQL database created and initialized
- ✅ Admin user created (admin/admin123)
- ✅ Health check ready

**URLs:**
- API: http://localhost:8080/
- Admin Panel: http://localhost:8080/admin/
- Health Check: http://localhost:8080/health.php

**Test it:**
```bash
# List buckets
curl -u admin:admin123 http://localhost:8080/

# Create bucket
curl -X PUT -u admin:admin123 http://localhost:8080/my-bucket

# Upload file
curl -X PUT -u admin:admin123 -d "Hello World" http://localhost:8080/my-bucket/hello.txt

# Download file
curl -u admin:admin123 http://localhost:8080/my-bucket/hello.txt
```

---

### 🖥️ LAMP/XAMPP/WAMP (Auto Setup)

```bash
git clone https://github.com/nityam2007/lite-s3.git
cd lite-s3
./setup.sh
```

The setup script will:
1. Check PHP and extensions
2. Ask for database credentials
3. Create database and import schema
4. Generate `config.php`
5. Set permissions

Then point your web server to the `WWW/` folder.

---

### 🏠 Shared Hosting (cPanel/DirectAdmin/Plesk)

1. **Download** this repo and upload `WWW/` contents to your `public_html`
2. **Create MySQL Database** in cPanel → MySQL Databases
3. **Run Installer** at `https://yourdomain.com/install.php`
4. **Delete** `install.php` after setup
5. **Login** with `admin` / `admin123` and **change password!**

---

### ⚙️ Manual Setup (Any Environment)

1. **Copy** `config.sample.php` to `config.php`
2. **Edit** `config.php` with your database credentials
3. **Import** `schema.sql` into your MySQL database
4. **Ensure** these directories are writable: `storage/`, `logs/`, `uploads/`
5. **Visit** `/admin/` to login

## 🔧 API Usage

### Authentication

Lite-S3 supports multiple authentication methods:

| Method | Format | Best For |
|--------|--------|----------|
| **Basic Auth** | `-u access_key:secret_key` | Testing, simple scripts |
| **AWS Signature V4** | `AWS4-HMAC-SHA256` | Production, AWS SDKs |
| **AWS Signature V2** | `AWS access_key:signature` | Legacy tools |
| **Presigned URLs** | `?X-Amz-Signature=...` | Shareable links |

### Upload a File

```bash
# Using Basic Auth (simplest)
curl -X PUT -u your_access_key:your_secret_key \
  -T ./myfile.txt \
  https://yourdomain.com/my-bucket/myfile.txt

# Using AWS signature
curl -X PUT \
  -H "Authorization: AWS your_access_key:your_secret_key" \
  -T ./myfile.txt \
  https://yourdomain.com/my-bucket/myfile.txt
```

### Download a File

```bash
curl -H "Authorization: AWS your_access_key:your_secret_key" \
  https://yourdomain.com/my-bucket/myfile.txt -o myfile.txt
```

### With Python (boto3)

```python
import boto3

s3 = boto3.client('s3',
    endpoint_url='https://yourdomain.com',
    aws_access_key_id='your_access_key',
    aws_secret_access_key='your_secret_key'
)

# Upload
s3.upload_file('local.txt', 'my-bucket', 'remote.txt')

# Download  
s3.download_file('my-bucket', 'remote.txt', 'local.txt')
```

### With rclone

```ini
[mycloud]
type = s3
provider = Other
endpoint = https://yourdomain.com
access_key_id = your_access_key
secret_access_key = your_secret_key
```

## ⚠️ Known Limitations

Lite-S3 implements core S3 features but is **not** 100% compatible with the full AWS S3 spec.

| Feature | Status | Notes |
|---------|--------|-------|
| **List Buckets** | ✅ Complete | |
| **Create/Delete Bucket** | ✅ Complete | |
| **Put/Get/Delete Object** | ✅ Complete | |
| **List Objects (v1 & v2)** | ✅ Complete | |
| **Head Object** | ✅ Complete | |
| **CopyObject** | ✅ Complete | Server-side copy |
| **Range Requests** | ✅ Complete | Byte-range downloads |
| **Presigned URLs** | ✅ Complete | |
| **AWS Signature V4** | ✅ Complete | |
| **AWS Signature V2** | ✅ Complete | |
| **Basic Auth** | ✅ Complete | |
| **Multipart Uploads** | ✅ Complete | Full support |
| **Rate Limiting** | ✅ Complete | Configurable limits |
| **Security Scanning** | ✅ Complete | Blocks dangerous files |
| **Versioning** | ❌ Missing | Overwrites delete old |
| **Object ACLs** | ❌ Missing | Bucket-level only |

## 📋 Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10+
- `mod_rewrite` enabled (Apache) or equivalent (nginx)
- `.htaccess` support or nginx config

**PHP Extensions (auto-detected):**
- `pdo`, `pdo_mysql` - Database
- `json` - API responses
- `hash` - Authentication
- `mbstring` - String handling

Most shared hosting plans already have all of this! 🎉

## 🔒 Security Notes

1. **Delete `install.php`** after setup
2. **Change default password** immediately (`admin` → something secure)
3. **Use HTTPS** in production (required for AWS SDK signature verification)
4. **Regular backups** of both database and `storage/` folder
5. **Set proper permissions** - `storage/`, `logs/`, `uploads/` should be writable by web server
6. **Keep `config.php` secure** - never commit to public repos

## 📊 Monitoring

Health check endpoint for load balancers and monitoring:

```bash
curl http://localhost:8080/health.php
```

Returns JSON with status of database, storage, and extensions.

## 💬 Support & Links

- **Author:** Nityam Sheth
- **GitHub:** [@nityam2007](https://github.com/nityam2007)
- **Issues:** [Report bugs](https://github.com/nityam2007/lite-s3/issues)

## 📜 License

This project is licensed under **GNU GPLv3** with attribution requirements.

**You can:**
- ✅ Use commercially
- ✅ Modify and distribute
- ✅ Use for any purpose

**You must:**
- 📌 Keep attribution/credits visible
- 📌 Release modifications under GPLv3
- 📌 Link back to original project

See [LICENSE](LICENSE) for details.

---

<div align="center">

**Made with ❤️ by [Nityam Sheth](https://github.com/nityam2007)**

*If this helped you, give it a ⭐!*

</div>