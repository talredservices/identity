FROM node:22-alpine AS build

WORKDIR /app
RUN corepack enable

# Workspace manifests, Nuxt config, and dependency patches must be present
# before postinstall runs nuxt prepare.
COPY . .
RUN pnpm install --frozen-lockfile
RUN pnpm build

FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000

COPY --from=build /app/.output ./.output

CMD ["node", ".output/server/index.mjs"]
