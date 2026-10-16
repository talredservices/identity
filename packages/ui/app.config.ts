export default defineAppConfig({
  ui: {
    colors: {
      primary: 'blue',
      secondary: 'blue',
      neutral: 'slate'
    },
    avatar: {
      slots: {
        root: 'rounded-md',
        fallback: 'text-inherit font-bold'
      }
    }
  }
})
