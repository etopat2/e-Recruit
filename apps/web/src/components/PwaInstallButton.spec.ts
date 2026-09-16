import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import { describe, expect, it, vi } from 'vitest'
import PwaInstallButton from './PwaInstallButton.vue'

describe('PwaInstallButton', () => {
  it('offers the captured browser install prompt and hides after acceptance', async () => {
    const prompt = vi.fn().mockResolvedValue(undefined)
    const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
      prompt: () => Promise<void>
      userChoice: Promise<{ outcome: 'accepted'; platform: string }>
    }
    event.prompt = prompt
    event.userChoice = Promise.resolve({ outcome: 'accepted', platform: 'web' })
    render(PwaInstallButton)

    window.dispatchEvent(event)
    const button = await screen.findByRole('button', { name: 'Install app' })
    expect(event.defaultPrevented).toBe(true)
    await fireEvent.click(button)

    await waitFor(() => expect(prompt).toHaveBeenCalledOnce())
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Install app' })).not.toBeInTheDocument())
  })

  it('stays hidden when no browser installation prompt is available', () => {
    render(PwaInstallButton)
    expect(screen.queryByRole('button', { name: 'Install app' })).not.toBeInTheDocument()
  })
})
