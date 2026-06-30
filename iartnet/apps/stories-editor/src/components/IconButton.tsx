import type { ReactNode } from 'react'
import { Button, type ButtonProps } from 'react-bootstrap'

type Props = Omit<ButtonProps, 'children'> & {
  /** Nome classe Bootstrap Icons senza prefisso `bi-` (es. `plus-lg`). */
  icon: string
  children?: ReactNode
}

export function IconButton({ icon, children, className, ...rest }: Props) {
  return (
    <Button
      className={[className, 'd-inline-flex align-items-center'].filter(Boolean).join(' ')}
      {...rest}
    >
      <i className={`bi bi-${icon}${children ? ' me-1' : ''}`} aria-hidden />
      {children}
    </Button>
  )
}
