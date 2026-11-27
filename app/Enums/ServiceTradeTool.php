<?php

namespace App\Enums;

/**
 * The tools a service business can use (repair workshop, salon, spa, gym,
 * personal training), the WholesaleTool / OnlineTool pattern: which trade gets
 * which is decided in one place, BusinessCategory::serviceTradeTools(), and a
 * shop that combines two related trades (salon + spa) gets both lists. A shop
 * asks with Shop::usesTool(), and a screen behind one is routed with the
 * shop.tool middleware (404 without it).
 *
 * Values are shared with WholesaleTool and OnlineTool in the shop.tool
 * middleware, so no value may repeat across the three enums.
 */
enum ServiceTradeTool: string
{
    /** Job cards: the units brought in, status, technician, parts, labour, estimate, warranty. */
    case JobCards = 'job_cards';
    /** A priced service menu with durations; services sell as lines on a sale, with who did them. */
    case ServiceMenu = 'service_menu';
    /** Bookings on a staff day calendar (and classes / personal training). */
    case Appointments = 'appointments';
    /** The walk-in queue: token numbers, waiting, in the chair. */
    case WalkIns = 'walk_ins';
    /** Commission per staff member on what they did, fixed when payroll is paid. */
    case StaffEarnings = 'commission';
    /** Memberships (by time) and packages (by sessions), sold on the bill. */
    case Memberships = 'memberships';
    /** Gift vouchers sold now and spent later by whoever holds the code. */
    case GiftVouchers = 'vouchers';
    /** Advance deposits on a booking or a job card, used on its bill. */
    case Deposits = 'deposits';
    /** Members checking in at the desk, with their membership and dues in view. */
    case CheckIns = 'check_ins';
    /** Fingerprint check-in from a ZKTeco-protocol (ADMS push) device. */
    case Biometric = 'biometric';
    /** Notes on a client and their visit history. */
    case ClientNotes = 'client_notes';
    /** A message (a diet plan, a timing change) sent to many customers at once. */
    case Broadcasts = 'broadcasts';
    /** Staff clocking in and out (fingerprint, QR badge or at the desk), days present into Payroll. */
    case StaffAttendance = 'attendance';
}
