import { cn } from "@/lib/utils";

interface ProgressProps {
  value: number;
  max: number;
  className?: string;
  indicatorClassName?: string;
  label: string;
}

export function Progress({ value, max, className, indicatorClassName, label }: ProgressProps) {
  const ratio = max > 0 ? Math.min(1, Math.max(0, value / max)) : 0;
  return (
    <div
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={max}
      aria-valuenow={Math.min(value, max)}
      className={cn("h-1 w-full overflow-hidden rounded-full bg-foreground/10", className)}
    >
      <div
        className={cn("h-full rounded-full bg-primary transition-[width] duration-700 ease-out", indicatorClassName)}
        style={{ width: `${ratio * 100}%` }}
      />
    </div>
  );
}
