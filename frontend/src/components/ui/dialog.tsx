"use client";

import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import * as React from "react";

import { cn } from "@/lib/utils";

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;
export const DialogDescription = DialogPrimitive.Description;

function Overlay({ className, ...props }: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      className={cn("fixed inset-0 z-50 bg-foreground/15 backdrop-blur-[3px]", className)}
      {...props}
    />
  );
}

interface ContentProps extends React.ComponentProps<typeof DialogPrimitive.Content> {
  side?: "center" | "right";
}

/** Modale centrée ou tiroir latéral flottant (`side="right"`), en verre dépoli. */
export function DialogContent({ className, children, side = "center", ...props }: ContentProps) {
  return (
    <DialogPrimitive.Portal>
      <Overlay />
      <DialogPrimitive.Content
        className={cn(
          "glass-strong fixed z-50 flex flex-col gap-5 p-7 text-popover-foreground focus:outline-none",
          side === "center" &&
            "left-1/2 top-1/2 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-3xl",
          side === "right" &&
            "inset-y-3 right-3 w-[calc(100vw-1.5rem)] max-w-md overflow-y-auto rounded-3xl",
          className,
        )}
        {...props}
      >
        {children}
        <DialogPrimitive.Close
          className="absolute right-5 top-5 grid size-8 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="Fermer"
        >
          <X className="size-4" />
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}
