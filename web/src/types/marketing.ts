/**
 * Shared vocabulary for lead capture (catering/corporate enquiries) and the
 * the teaser waitlist. These describe what a CUSTOMER tells us about their event —
 * they are not claims Dough Boss makes about its food.
 */

export const CATERING_EVENT_TYPES = [
  "OFFICE_BREAKFAST",
  "TEAM_LUNCH",
  "MEETING",
  "CORPORATE_EVENT",
  "PARTY",
  "WEDDING_ENGAGEMENT",
  "COMMUNITY_RELIGIOUS",
  "OTHER",
] as const;
export type CateringEventType = (typeof CATERING_EVENT_TYPES)[number];

export const CATERING_EVENT_LABELS: Record<CateringEventType, string> = {
  OFFICE_BREAKFAST: "Office breakfast",
  TEAM_LUNCH: "Team lunch",
  MEETING: "Meeting or boardroom",
  CORPORATE_EVENT: "Corporate event",
  PARTY: "Party or celebration",
  WEDDING_ENGAGEMENT: "Wedding or engagement",
  COMMUNITY_RELIGIOUS: "Community or religious event",
  OTHER: "Something else",
};

/** Headcount bands — volume is the lead-scoring signal for corporate work. */
export const GUEST_BANDS = ["UP_TO_25", "FROM_26_TO_50", "FROM_51_TO_100", "FROM_101_TO_250", "OVER_250"] as const;
export type GuestBand = (typeof GUEST_BANDS)[number];

export const GUEST_BAND_LABELS: Record<GuestBand, string> = {
  UP_TO_25: "Up to 25 guests",
  FROM_26_TO_50: "26 – 50 guests",
  FROM_51_TO_100: "51 – 100 guests",
  FROM_101_TO_250: "101 – 250 guests",
  OVER_250: "More than 250 guests",
};

export const LEAD_STATUSES = ["NEW", "CONTACTED", "TASTING_OFFERED", "QUOTED", "WON", "LOST"] as const;
export type LeadStatus = (typeof LEAD_STATUSES)[number];

export type EnquiryFailureCode = "VALIDATION" | "RATE_LIMITED" | "STORAGE_UNAVAILABLE" | "UNKNOWN";

export type CateringEnquiryResult =
  | { ok: true; reference: string }
  | {
      ok: false;
      code: EnquiryFailureCode;
      message: string;
      fieldErrors?: Record<string, string>;
      retryAfterSeconds?: number;
    };
