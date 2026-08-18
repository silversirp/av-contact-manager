# AV Contact Manager

A lightweight WordPress plugin providing a GDPR-conscious contact/inquiry form via shortcode, with submission storage, email notifications, and an admin dashboard for managing entries.

## Features

- **Shortcode form** — `[av_contact_form]` renders a contact form (name, email, event, date, location, description) with consent checkbox.
- **Multi-instance support** — place multiple independently configured forms on a site using the `to`, `reply_to`, and `subject` shortcode attributes, e.g.:
  ```
  [av_contact_form to="info@band.ee, manager@band.ee" subject="Bänd"]
  ```
- **Smart Reply-To logic** — falls back through per-shortcode `reply_to`, per-shortcode `to`, a global default, then a hardcoded fallback address.
- **Admin dashboard** — view, inspect, and delete submitted entries (`Päringud` menu), with the source page URL and subject stored per entry.
- **Settings page** — configure default recipient, reply-to, sender address, and SMTP host/port/user/password (SMTP password stored encrypted with AES-256-CBC).
- **Security**
  - Nonce verification and honeypot field on submission.
  - Post/Redirect/Get (PRG) flow with a one-time success transient to prevent duplicate submissions on refresh.
  - Rate limiting per IP+User-Agent fingerprint.
  - Input sanitization/length limits and email header injection protection.
  - Content-Security-Policy header sent on pages containing the form.
  - Custom `av_view_submissions` capability (granted to Administrators) gates the entries dashboard.
- **Data retention** — a daily scheduled cleanup job deletes entries older than 1 year.
- **Clean uninstall** — `uninstall.php` drops the plugin's database table, deletes its options, and clears the scheduled cleanup event.

## Installation

1. Copy the plugin folder into `wp-content/plugins/`, or upload `av-contact-manager.zip` via **Plugins → Add New → Upload Plugin** in WP admin.
2. Activate **AV Contact Manager** from the Plugins page.
3. Go to **Päringud → Seaded** to configure the default recipient, reply-to, sender, and SMTP settings.
4. Add `[av_contact_form]` to any page or post.

## Usage

Basic form:

```
[av_contact_form]
```

Multiple independently addressed forms on the same site:

```
[av_contact_form to="info@band.ee, manager@band.ee" subject="Bänd"]
```

| Attribute  | Description                                                        |
|------------|----------------------------------------------------------------------|
| `to`       | Recipient address(es) for this form instance (comma-separated).      |
| `reply_to` | Reply-To address for the admin notification email.                   |
| `subject`  | Prefix added to the email subject and stored with the submission.    |

## Requirements

- WordPress
- PHP with the `openssl` extension (for encrypted SMTP password storage)

## File Structure

```
av-contact-manager.php   Main plugin file (form, admin UI, mail, settings)
uninstall.php            Cleanup on plugin deletion
css/av-style.css         Frontend form styles
css/av-admin.css         Admin dashboard styles
js/av-script.js          Frontend form behavior (datepicker, char counter, AJAX-free submit)
js/av-admin.js           Admin dashboard behavior
```

## Author

Silver Sirp
