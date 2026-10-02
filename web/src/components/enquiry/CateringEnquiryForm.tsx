import type { Store } from "@/types/menu";
import type { CateringEventType } from "@/types/marketing";

export interface CateringEnquiryFormProps {
  stores: Store[];
  /** Pre-selects the event type on that page, e.g. "OFFICE_BREAKFAST" on /catering/office-breakfast. */
  defaultEventType?: CateringEventType;
  /** Identifies where the form sits, for attribution: e.g. "catering-corporate". */
  source: string;
  className?: string;
}

/**
 * STUB with the final props contract. The growth slice replaces the body with
 * the real two-step enquiry form; pages already import and render it.
 */
export function CateringEnquiryForm({ source, className }: CateringEnquiryFormProps) {
  return (
    <div className={className} data-testid="catering-enquiry-form" data-source={source}>
      <p className="text-sm text-muted">Quote request form coming online soon.</p>
    </div>
  );
}
