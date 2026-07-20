# Bon Avion — Airline Booking Management System

> A PHP and MySQL web application for airline passengers and airline administrators. It brings flight discovery, booking, payment, membership benefits, and operational reporting into one database-backed workflow.

## Highlights

### Membership-driven pricing

The system calculates a customer's accumulated payment history and applies the corresponding fare discount throughout the booking journey:

| Tier | Total paid | Discount |
| --- | ---: | ---: |
| Member | ≤ $5,000 | — |
| Silver | > $5,000 | 5% |
| Gold | > $10,000 | 10% |
| Platinum | > $20,000 | 15% |

The membership module is reusable across the search-result, booking, payment, profile, and membership pages. It also exposes the spend required for the next tier. The design is intended to work with a database trigger that updates the stored customer level after payments.

### Availability-aware flight search

Search results are not static. For every flight, Bon Avion derives remaining capacity separately for First, Business, and Economy by combining aircraft seat capacity with issued tickets. The selected cabin and passenger count are checked before a customer can continue to booking, and low availability is surfaced in the interface.

### End-to-end booking and payment flow

1. A signed-in customer selects a flight and cabin.
2. The system creates a six-character PNR and one e-ticket record per passenger with a `PENDING` booking status.
3. The payment page applies the membership fare adjustment, calculates optional extra/overweight baggage charges, records payment, and confirms the booking.
4. Customers can revisit their orders and see flight and booking status.

This flow models the relationships between bookings, tickets, payments, luggage, flights, aircraft, and customers rather than treating payment as a front-end-only action.

### Airline-scoped operations console

Airline administrators authenticate separately from customers. Their session is tied to an IATA airline code, so flight-management actions and booking reports are restricted to their own airline. The console supports:

- Creating, updating, and managing flights with aircraft ownership checks.
- CSRF protection for flight-management form submissions.
- Flight/date booking lookup with passenger contact details, PNRs, e-tickets, and luggage counts.
- Per-cabin capacity, bookings, remaining seats, total revenue, and average revenue statistics.

## Core user features

- Flight search by route, travel date, passenger count, trip type, and cabin.
- Customer registration, sign-in, profile updates, and avatar upload/compression.
- Destination recommendations for Tokyo, Seoul, Paris, New York, Bangkok, and Singapore.
- Booking history and a membership-progress view.
- Payment choices and baggage add-ons.

## Technology

- PHP (mysqli and PDO-compatible membership helper)
- MySQL / MariaDB
- HTML, CSS, and vanilla JavaScript
- Font Awesome and Google Fonts (loaded from CDNs)

## Project layout

```text
.
├── index.php                     # Flight-search landing page
├── flightResults.php             # Results, fare display, and seat availability
├── booking.php                   # PNR and e-ticket creation
├── payment.php                   # Payment confirmation and baggage charges
├── membership.php                # Tier, discount, and progress calculations
├── myOrders.php                  # Customer booking history
├── profile.php / update_profile.php
├── recommendation.php            # Destination-led flight discovery
├── admin_login.php
├── admin_flight_management.php   # Airline-scoped flight administration
├── admin_booking_status.php      # Booking, capacity, and revenue reporting
├── avatar_helper.php             # Avatar caching and compression helpers
├── css/
└── image/
```

## Run locally

### Prerequisites

- PHP 7.4+ with `mysqli` enabled (GD is recommended for avatar compression)
- MySQL 8+ or MariaDB
- A local PHP web server such as XAMPP, WampServer, or PHP's built-in server

### Setup

1. Place the project in your web-server document root, or start a local server from this directory:

   ```bash
   php -S localhost:8000
   ```

2. Create a database named `bonavion` and import the project's database schema and seed data.

3. Update the database connection variables near the top of the PHP entry pages if your local credentials differ:

   ```php
   $servername = "localhost";
   $username = "root";
   $password = "";
   $dbname = "bonavion";
   ```

4. Open `http://localhost:8000/index.php`.

> **Database note:** this repository version does not include a `.sql` schema/export. To make the project fully reproducible, add a sanitized `database/bonavion.sql` export (schema, seed data, and the membership-level trigger) and link it here. Do not commit real customer data, passwords, or production credentials.

## Screenshots

Add screenshots here after capturing them locally:

```text
docs/screenshots/home.png
docs/screenshots/search-results.png
docs/screenshots/admin-report.png
```

Then replace this section with:

```md
![Flight search](docs/screenshots/home.png)
![Availability-aware search results](docs/screenshots/search-results.png)
![Airline booking and revenue report](docs/screenshots/admin-report.png)
```

## Data model at a glance

```text
Customer ──< Booking ──< Ticket >── Flight ──> Aircraft
                 │           │
                 │           └──< Luggage
                 └──< Payment

Flight ──> Airline
Flight ──> Departure Airport / Arrival Airport
Airline ──< Airline Administrator
```

## Security and implementation notes

- Customer and administrator authentication use separate sessions.
- Database queries commonly use prepared statements.
- Administrator flight-management requests include a session CSRF token.
- The repository currently contains local development connection settings. Use environment variables or a private configuration file before deploying anywhere public.

## Future improvements

- Add the database schema, migrations, seed data, and automated setup script.
- Centralize database configuration and move secrets outside version control.
- Wrap booking/payment writes in database transactions and add server-side capacity checks at booking time.
- Add automated tests and role-based authorization middleware.

## Academic context

Built as a database course project, with the application layer designed to demonstrate a relational airline booking domain and its operational workflows.
