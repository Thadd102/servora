/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.php",
    "./client/**/*.php",
    "./admin/**/*.php",
    "./includes/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        servora: {
          50: '#F5F3FF',
          100: '#EDE9FE',
          200: '#DDD6FE',
          300: '#C4B5FD',
          400: '#A78BFA',
          500: '#635BDB',
          600: '#5146C7',
          700: '#3E37B7',
          800: '#312E81',
          900: '#1E1B4B'
        },
        subnext: {
          50: '#F5F3FF',
          100: '#EDE9FE',
          200: '#DDD6FE',
          300: '#C4B5FD',
          400: '#A78BFA',
          500: '#635BDB',
          600: '#5146C7',
          700: '#3E37B7',
          800: '#312E81',
          900: '#1E1B4B'
        }
      }
    },
  },
  plugins: [],
}
