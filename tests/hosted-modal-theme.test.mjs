import assert from 'node:assert/strict'
import { test } from 'node:test'
import { hostedModalThemeStyle } from '../layers/auth/default-pages/app/utils/hosted-modal-theme.ts'

test('uses the selected application palette for modal surfaces and accents', () => {
  const style = hostedModalThemeStyle({
    accentColor: '#16937d',
    designTokens: {
      light_background: '#f7f8f6',
      light_background_muted: '#eef3ef',
      light_card: '#ffffff',
      light_text: '#172033',
      light_muted: '#59657b',
      light_border: '#dce7e2',
      primary_500: '#16937d',
      primary_600: '#0b7867',
      primary_700: '#096052'
    }
  }, 'light')

  assert.equal(style['--ui-color-primary-500'], '#16937d')
  assert.equal(style['--ui-color-primary-600'], '#0b7867')
  assert.equal(style['--ui-bg'], '#f7f8f6')
  assert.equal(style['--ui-bg-elevated'], '#ffffff')
  assert.equal(style['--ui-text-muted'], '#59657b')
  assert.equal(style['--ui-border'], '#dce7e2')
  assert.equal(style.colorScheme, 'light')
})

test('uses dark application tokens while keeping another product accent isolated', () => {
  const style = hostedModalThemeStyle({
    accentColor: '#8b5cf6',
    designTokens: {
      dark_background: '#020617',
      dark_background_muted: '#17102b',
      dark_card: '#100b1f',
      dark_text: '#f8fafc',
      dark_muted: '#94a3b8',
      dark_border: '#1e293b',
      primary_500: '#8b5cf6',
      primary_600: '#7c3aed',
      primary_700: '#6d28d9'
    }
  }, 'dark')

  assert.equal(style['--ui-color-primary-500'], '#8b5cf6')
  assert.equal(style['--ui-color-primary-600'], '#7c3aed')
  assert.equal(style['--ui-bg'], '#020617')
  assert.equal(style['--ui-bg-muted'], '#17102b')
  assert.equal(style['--ui-bg-elevated'], '#100b1f')
  assert.equal(style['--ui-text-highlighted'], '#f8fafc')
  assert.equal(style.colorScheme, 'dark')
})

test('leaves non-hosted modal styling unchanged when no theme is supplied', () => {
  assert.deepEqual(hostedModalThemeStyle(null, 'light'), {})
})
