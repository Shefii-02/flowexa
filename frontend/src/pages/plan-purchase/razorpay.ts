// Shared Razorpay checkout helper for plan and add-on purchases.
declare global { interface Window { Razorpay: any } }

export async function loadRazorpay(): Promise<void> {
  if (window.Razorpay) return
  await new Promise<void>((res, rej) => {
    const s = document.createElement('script')
    s.src = 'https://checkout.razorpay.com/v1/checkout.js'
    s.onload = () => res(); s.onerror = () => rej(new Error('Could not load the payment window. Check your connection and try again.'))
    document.body.appendChild(s)
  })
}
