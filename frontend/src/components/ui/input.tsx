import * as React from "react";

import { cn } from "@/lib/utils";

export function Input({ className, ...props }: React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className={cn(
        "flex h-10 w-full rounded-xl border border-input bg-white/60 px-3.5 py-1 text-sm text-foreground shadow-[inset_0_1px_2px_rgb(0_0_0/0.04)] backdrop-blur transition-colors placeholder:text-muted-foreground/70 focus-visible:bg-white/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 aria-[invalid=true]:border-destructive",
        className,
      )}
      {...props}
    />
  );
}

export function Label({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn("text-xs font-medium tracking-wide text-muted-foreground", className)} {...props} />;
}
