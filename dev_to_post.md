---
title: I built my own S3 for $5/mo Shared Hosting (because no one else did)
published: false
description: A pure PHP S3-compatible object storage server that runs on cPanel/Shared Hosting.
tags: php, selfhosted, s3, open source
cover_image: https://raw.githubusercontent.com/nityam2007/lite-s3/master/docs/screenshots/dashboard.png
---

# The Problem: S3 is expensive, and MinIO needs VPS

I was looking for a way to have **S3-compatible object storage** without paying AWS/Cloudflare/Backblaze fees, and specifically, I wanted to run it on the cheap **Shared Hosting** (cPanel) plan I already pay for.

The problem?
1.  **MinIO** requires a binary/Docker (can't run on shared hosting).
2.  **LocalStack** is for testing, not storage.
3.  Existing PHP scripts were either 10 years old, unmaintained, or didn't support the S3 API properly.

I wanted something I could just FTP upload, create a MySQL database, and boom—my own personal S3 bucket.

# The Solution: Lite-S3

Since it didn't exist, I built it (with a lot of help from AI agents).

**[Lite-S3](https://github.com/nityam2007/lite-s3)** is a pure PHP implementation of an S3-compatible server.

### What it does:
*   ✅ **S3 API Compatible**: Works with AWS SDK, `boto3`, `rclone`, etc.
*   ✅ **Shared Hosting Friendly**: Runs on standard PHP 8.0+ and MySQL (cPanel, Plesk).
*   ✅ **Multi-User**: Create users with their own Access/Secret keys.
*   ✅ **Permissions**: Read/Write/Admin access controls per bucket.
*   ✅ **Large Files**: Supports 5GB+ uploads (using PHP streams).
*   ✅ **Admin UI**: A clean dashboard to manage everything.

### How it works (The Technical Part)

It maps S3 API calls to PHP functions.

1.  **Authentication**: It verifies standard **AWS Signature v4** headers against credentials stored in MySQL. This was the hardest part to get right in PHP!
2.  **Routing**: A simple `.htaccess` routes specific S3 paths (`/bucket/key`) to the handler.
3.  **Storage**: Files are stored as **plain flat files** on the disk. This means you can easily FTP in and backup your data; it's not locked in a proprietary format.
4.  **Multipart Uploads**: It implements the full S3 multipart upload flow (Initiate -> Upload Part -> Complete) to handle large files without hitting PHP memory limits.

### Is it 100% S3 Compatible?

**Honest answer: No.**

It implements the *Core CRUD* operations that 95% of apps use:
*   `PutObject` (Upload)
*   `GetObject` (Download)
*   `DeleteObject`
*   `ListObjectsV2`
*   `CreateBucket` / `DeleteBucket`

It's **missing** things like:
*   Object Versioning
*   Object-level ACLs (permissions are per-bucket)

But it **does support**:
*   ✅ Range Requests (byte-range downloads for video seeking)
*   ✅ CopyObject (server-side copy between buckets)
*   ✅ Multipart Uploads (for large files)

For "I need a place to dump my backups/images via API," it works perfectly.

### Try it out

It's fully open source (GPLv3) and I'd love for people to test it on their cheap hosting plans and break it.

🔗 **GitHub Repo**: [nityam2007/lite-s3](https://github.com/nityam2007/lite-s3)

*(Note: This project was heavily assisted by AI coding agents, which helped me sanity check the AWS Signature logic and build the UI quickly. We live in the future!)*
