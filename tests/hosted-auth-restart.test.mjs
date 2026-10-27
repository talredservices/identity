import assert from 'node:assert/strict'
import { test } from 'node:test'
import { hostedAuthRestartAction, hostedAuthRestartUrl } from '../layers/auth/default-pages/app/utils/hosted-auth-restart.ts'

test('restarts from the localized consumer login route and returns to its app path', () => {
  assert.equal(
    hostedAuthRestartUrl({
      returnUrl: 'http://localhost:3000/en/jobs',
      identityOrigin: 'http://localhost:3100',
      action: 'login'
    }),
    'http://localhost:3000/en/auth/login?redirect=%2Fen%2Fjobs'
  )
})

test('keeps a safe requested redirect when restarting hosted authorization', () => {
  assert.equal(
    hostedAuthRestartUrl({
      returnUrl: 'https://jobs.example.com/fr/jobs',
      identityOrigin: 'https://identity.example.com',
      action: 'login',
      redirect: '/fr/dashboard?view=saved'
    }),
    'https://jobs.example.com/fr/auth/login?redirect=%2Ffr%2Fdashboard%3Fview%3Dsaved'
  )
})

test('uses the root login route for an unlocalized consumer root', () => {
  assert.equal(
    hostedAuthRestartUrl({
      returnUrl: 'http://localhost:3300/',
      identityOrigin: 'http://localhost:3100',
      action: 'login'
    }),
    'http://localhost:3300/auth/login'
  )
})

test('does not restart to the Identity host or an unsafe URL', () => {
  assert.equal(
    hostedAuthRestartUrl({
      returnUrl: 'http://localhost:3100/en/jobs',
      identityOrigin: 'http://localhost:3100',
      action: 'login'
    }),
    null
  )
  assert.equal(
    hostedAuthRestartUrl({
      returnUrl: 'javascript:alert(1)',
      identityOrigin: 'http://localhost:3100',
      action: 'login'
    }),
    null
  )
})

test('maps the hosted page screen to the consumer login action', () => {
  assert.equal(hostedAuthRestartAction('/auth/job-tracker/login'), 'login')
  assert.equal(hostedAuthRestartAction('/auth/job-tracker/register'), 'signup')
  assert.equal(hostedAuthRestartAction('/auth/job-tracker/forgot-password'), 'forgot-password')
  assert.equal(hostedAuthRestartAction('/auth/job-tracker/reset-password'), 'reset-password')
})
