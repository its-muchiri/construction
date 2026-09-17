<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Request;
use Construction\Core\View;
use Construction\Models\ConstructionBooking;
use Throwable;

/**
 * Server-rendered pages for the primary customer journey — post a project,
 * browse equipment/crew listings, view a provider profile, track a
 * project's quote/milestone lifecycle — per
 * planning/03-construction-co-ke/user-flows.md §1. Admin/dispute consoles
 * are not built here, matching the scope discipline set by
 * laundry.co.ke's page layer (see
 * planning/00-portfolio/ui-implementation-plan.md §3).
 *
 * Every DB-backed method fails soft: this environment (a fresh Vercel
 * deploy with no database wired yet) has no live connection, so a page
 * must still render a meaningful empty state rather than a fatal error,
 * per artcollect-design-system.md §8.
 */
final class PageController
{
    public function home(Request $request): void
    {
        $equipment = [];
        $crew = [];
        $dbError = null;

        try {
            $db = Database::connection();
            $equipment = $db->query(
                'SELECT id, provider_id, equipment_category, make_model, daily_rate FROM equipment_listings WHERE status = "active" ORDER BY id DESC LIMIT 4'
            )->fetchAll();
            $crew = $db->query(
                'SELECT id, provider_id, trade, team_size, day_rate FROM crew_listings WHERE status = "active" ORDER BY id DESC LIMIT 4'
            )->fetchAll();
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live listing data is unavailable in this environment — no database is connected yet.';
        }

        View::render('home', [
            'title' => 'Equipment rental and labor contracts, quoted and escrowed',
            'equipment' => $equipment,
            'crew' => $crew,
            'dbError' => $dbError,
        ]);
    }

    public function projectForm(Request $request): void
    {
        View::render('project-new', ['title' => 'Post a project']);
    }

    public function equipmentIndex(Request $request): void
    {
        $category = $request->query['category'] ?? null;
        $listings = [];
        $dbError = null;

        try {
            $db = Database::connection();
            if ($category) {
                $stmt = $db->prepare('SELECT * FROM equipment_listings WHERE status = "active" AND equipment_category = :category');
                $stmt->execute(['category' => $category]);
            } else {
                $stmt = $db->query('SELECT * FROM equipment_listings WHERE status = "active"');
            }
            $listings = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live listing data is unavailable in this environment — no database is connected yet.';
        }

        View::render('equipment-index', [
            'title' => 'Browse equipment',
            'listings' => $listings,
            'category' => $category,
            'dbError' => $dbError,
        ]);
    }

    public function crewIndex(Request $request): void
    {
        $trade = $request->query['trade'] ?? null;
        $listings = [];
        $dbError = null;

        try {
            $db = Database::connection();
            if ($trade) {
                $stmt = $db->prepare('SELECT * FROM crew_listings WHERE status = "active" AND trade = :trade');
                $stmt->execute(['trade' => $trade]);
            } else {
                $stmt = $db->query('SELECT * FROM crew_listings WHERE status = "active"');
            }
            $listings = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live listing data is unavailable in this environment — no database is connected yet.';
        }

        View::render('crew-index', [
            'title' => 'Browse crews',
            'listings' => $listings,
            'trade' => $trade,
            'dbError' => $dbError,
        ]);
    }

    public function providerProfile(Request $request): void
    {
        $providerId = (int) $request->params['id'];
        $provider = null;
        $dbError = null;

        try {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = "provider"');
            $stmt->execute(['id' => $providerId]);
            $provider = $stmt->fetch() ?: null;

            if ($provider) {
                $equipmentStmt = $db->prepare('SELECT * FROM equipment_listings WHERE provider_id = :id AND status = "active"');
                $equipmentStmt->execute(['id' => $providerId]);
                $provider['equipment_listings'] = $equipmentStmt->fetchAll();

                $crewStmt = $db->prepare('SELECT * FROM crew_listings WHERE provider_id = :id AND status = "active"');
                $crewStmt->execute(['id' => $providerId]);
                $provider['crew_listings'] = $crewStmt->fetchAll();
            }
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live provider data is unavailable in this environment — no database is connected yet.';
        }

        View::render('provider-profile', [
            'title' => $provider ? $provider['full_name'] : 'Provider #' . $providerId,
            'providerId' => $providerId,
            'provider' => $provider,
            'dbError' => $dbError,
        ]);
    }

    public function projectStatus(Request $request): void
    {
        $bookingId = (int) $request->params['id'];
        $booking = null;
        $quotes = [];
        $milestones = [];
        $dbError = null;

        try {
            $booking = ConstructionBooking::find($bookingId);

            if ($booking) {
                $db = Database::connection();
                $quotesStmt = $db->prepare('SELECT * FROM project_quotes WHERE booking_id = :id ORDER BY created_at DESC');
                $quotesStmt->execute(['id' => $bookingId]);
                $quotes = $quotesStmt->fetchAll();

                $milestonesStmt = $db->prepare('SELECT * FROM project_milestones WHERE booking_id = :id ORDER BY sequence_number ASC');
                $milestonesStmt->execute(['id' => $bookingId]);
                $milestones = $milestonesStmt->fetchAll();
            }
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live project data is unavailable in this environment — no database is connected yet.';
        }

        // Critical-flow: milestones represent the escrow-funding lifecycle
        // this platform holds large sums against — see this platform's
        // README and pages/checkout.js's enforced no-decoration rule
        // (artcollect-design-system.md §7). This tracking page mirrors that
        // same zero-decoration treatment.
        View::render('project-status', [
            'title' => 'Project #' . $bookingId,
            'bookingId' => $bookingId,
            'booking' => $booking,
            'quotes' => $quotes,
            'milestones' => $milestones,
            'dbError' => $dbError,
            'critical' => true,
        ]);
    }
}
