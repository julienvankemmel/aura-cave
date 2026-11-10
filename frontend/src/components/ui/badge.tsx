import { cva, type VariantProps } from "class-variance-authority";
import * as React from "react";

import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[0.7rem] font-medium tracking-wide [&_svg]:size-3",
  {
    variants: {
      variant: {
        default: "bg-primary/90 text-primary-foreground",
        secondary: "bg-secondary text-muted-foreground",
        outline: "border border-input text-muted-foreground",
        ok: "bg-ok/10 text-ok",
        warn: "bg-warn/12 text-warn",
        destructive: "bg-destructive/10 text-destructive",
      },
    },
    defaultVariants: { variant: "secondary" },
  },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
