import * as React from "react";
import { cn } from "@/lib/utils";

const controlClasses =
  "w-full rounded-2xl border border-white/15 bg-ink-950 px-4 text-base text-flour placeholder:text-muted/70 transition-colors duration-150 " +
  "hover:border-white/30 focus-visible:border-gold-400 focus-visible:outline-gold-400/40 aria-[invalid=true]:border-chili-500 disabled:opacity-50";

/** 16px text on purpose: anything smaller makes iOS Safari zoom the page on focus. */
export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(function Input(
  { className, type = "text", ...props },
  ref,
) {
  return <input ref={ref} type={type} className={cn(controlClasses, "h-12", className)} {...props} />;
});

export const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ className, rows = 3, ...props }, ref) {
    return <textarea ref={ref} rows={rows} className={cn(controlClasses, "min-h-24 py-3", className)} {...props} />;
  },
);

export function Label({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn("text-[0.72rem] font-semibold uppercase tracking-[0.16em] text-flour/80", className)} {...props} />;
}

/** The attributes a control needs so its error is announced and styled: spread onto Input/Textarea. */
export function fieldProps(id: string, error?: string) {
  return {
    id,
    "aria-invalid": error ? true : undefined,
    "aria-describedby": error ? `${id}-error` : undefined,
  } as const;
}

export interface FieldProps {
  id: string;
  label: React.ReactNode;
  error?: string;
  hint?: React.ReactNode;
  className?: string;
  children: React.ReactNode;
}

/**
 * Label + control + hint/error. The error is role="alert" so assistive tech
 * announces it when it appears; the control references it via fieldProps().
 */
export function Field({ id, label, error, hint, className, children }: FieldProps) {
  return (
    <div className={cn("grid gap-2", className)}>
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && !error ? <p className="text-xs text-muted">{hint}</p> : null}
      {error ? (
        <p id={`${id}-error`} role="alert" className="text-sm text-[#ff8d84]">
          {error}
        </p>
      ) : null}
    </div>
  );
}
