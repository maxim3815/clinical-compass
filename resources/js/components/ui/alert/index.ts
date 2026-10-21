import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Alert } from "./Alert.vue"
export { default as AlertDescription } from "./AlertDescription.vue"
export { default as AlertTitle } from "./AlertTitle.vue"

export const alertVariants = cva(
  "relative w-full rounded-xl border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*5)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground",
        info: "border-secondary bg-secondary text-secondary-foreground *:data-[slot=alert-description]:text-secondary-foreground",
        success: "border-success-border bg-success-subtle text-foreground [&>svg]:text-success *:data-[slot=alert-description]:text-foreground",
        warning: "border-warning-border bg-warning-subtle text-foreground [&>svg]:text-warning *:data-[slot=alert-description]:text-foreground",
        destructive:
          "border-danger-border bg-danger-subtle text-foreground [&>svg]:text-danger *:data-[slot=alert-description]:text-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)

export type AlertVariants = VariantProps<typeof alertVariants>
