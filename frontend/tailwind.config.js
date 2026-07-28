/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#e6f8f1',
          100: '#c3edd8',
          200: '#8dd9b5',
          300: '#5dc99a',
          400: '#2db87e',
          500: '#1D9E75', // primary
          600: '#0F6E56',
          700: '#085041',
          800: '#053429',
          900: '#031c16',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
      },
    },
  },
  plugins: [],
}
