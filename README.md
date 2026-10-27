# NexaCart

A full-stack e-commerce project built with Laravel, Next.js, and MySQL. Includes Google Sign-In, Stripe sandbox checkout, a Groq-powered shopping assistant, and customer and administrator dashboards.

## Tech Stack

| Layer | Technologies |
|---|---|
| Frontend | Next.js, React, TypeScript, Tailwind CSS |
| Backend | Laravel 12, PHP, Laravel Sanctum |
| Database | MySQL |
| Authentication | Session authentication, Google OAuth |
| Payments | Stripe Checkout and webhooks |
| AI | Groq API |
| Email | SMTP for verification and password recovery |

## Features

### Customers

- Register, sign in, and sign out.
- Continue with Google.
- Verify an email address and recover a password.
- Update profile details and change passwords.
- Browse, search, and filter products.
- Manage cart quantities and shipping details.
- Complete Stripe sandbox checkout.
- View orders and check payment status.
- Cancel eligible unpaid checkout sessions.
- Ask the AI shopping assistant for product guidance.

### Administrators

- View account statistics.
- Manage categories and products.
- Upload product images.
- Manage prices, stock, and product availability.
- View orders and update fulfillment status.
- Search customers and block or unblock accounts.

### Application

- Responsive storefront and dashboard layouts.
- Server-side validation and authorization.
- CSRF protection for session-based requests.
- Rate-limited endpoints.
- Stock reservations during checkout.
- Stripe webhook processing and scheduled payment reconciliation.

### Installation

```bash
git clone https://github.com/yunusbutt676-commits/nexacart.git
cd nexacart
cd nexacart/frontend
