import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-sm font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3.5 gap-1 [&>svg]:pointer-events-none focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-ring transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary-hover",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary-hover",
        destructive:
         "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90",
        outline:
          "border-border bg-card text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        muted:
          "border-transparent bg-muted text-muted-foreground",
        // Состояния: цвет повторяет смысл текста, а не заменяет его.
        success:
          "border-success-border bg-success-subtle text-success",
        warning:
          "border-warning-border bg-warning-subtle text-warning",
        danger:
          "border-danger-border bg-danger-subtle text-danger",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
