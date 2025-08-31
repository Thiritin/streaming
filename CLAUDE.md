# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based streaming system for Eurofurence (and other conventions) that manages live video streaming infrastructure. It includes server provisioning, client management, real-time chat, and auto-scaling capabilities.

## Core Architecture

### Tech Stack
- **Backend**: Laravel 12 with PHP 8.2+
- **Frontend**: Vue 3 with Inertia.js 2
- **Admin Panel**: Filament 3
- **Real-time**: Pusher/Soketi for WebSockets
- **Streaming**: SRS (Simple Realtime Server) for RTMP/FLV streaming
- **Queue**: Laravel Horizon with Redis
- **Database**: MySQL 8.0
- **Infrastructure**: Hetzner Cloud API for server provisioning

### Key Components

1. **Streaming Infrastructure**
   - SRS servers handle RTMP input and HTTP-FLV output
   - Origin and edge server architecture for scalability
   - Automatic server provisioning via Hetzner Cloud API

2. **Models & Relationships**
   - `User`: Attendees with authentication via OpenID
   - `Server`: Streaming servers (origin/edge types)
   - `Client`: Active streaming connections
   - `Message`: Chat messages with moderation features

3. **Real-time Features**
   - WebSocket broadcasting for chat and system events
   - Stream status updates (offline, provisioning, live)
   - Rate limiting and slow mode for chat

4. **Auto-scaling**
   - `AutoscalerService` monitors capacity and provisions/deprovisions servers
   - Jobs handle async server lifecycle (creation, DNS, deletion)

## Development Commands

### Local Development
```bash
# Install dependencies
composer install
npm install

# Run migrations and seeders
php artisan migrate --seed

# Start development servers
php artisan serve        # Laravel development server
npm run dev              # Vite dev server for assets
php artisan horizon      # Queue worker
php artisan octane:start # High-performance server (optional)

# Run with Docker Compose (includes all services)
docker-compose up
```

### Testing
```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific test file
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Queue Management
```bash
# Process jobs
php artisan queue:work

# Monitor with Horizon dashboard (visit /horizon)
php artisan horizon
```

## Environment Configuration

Key environment variables to configure:
- `HETZNER_API_TOKEN`: For server provisioning
- `PUSHER_*`: WebSocket configuration
- `OIDC_*`: OpenID Connect settings
- `STREAM_*`: Streaming server configuration
- `CHAT_*`: Chat moderation settings

## Docker Services

The `docker-compose.yml` includes:
- `laravel.test`: Main application container
- `mysql`: Database
- `redis`: Cache and queues
- `soketi`: WebSocket server
- `stream`: SRS edge server
- `origin`: SRS origin server

## Job Queue Architecture

Critical background jobs for server management:
- `CreateServerJob`: Provisions new Hetzner server
- `DeleteServerJob`: Deprovisions server
- `ScalingJob`: Auto-scaling decisions
- `ServerAssignmentJob`: Assigns users to servers
- Chat command jobs for moderation

## Admin Interface

Filament admin panel at `/admin` provides:
- Server management and monitoring
- Client connection tracking
- User management with role-based permissions
- Real-time capacity and performance widgets

## Important Development Rules

- **NEVER use fetch() or make API calls** unless absolutely necessary. Always use Inertia.js 2 props for passing data from backend to frontend. Data should be passed through page controllers or HandleInertiaRequests middleware for global data.
- Never use -gray- for tailwind colors always use -primary- as main color
- no need t orun build i got a npm run dev running
- Always use sail instead of docker-compose