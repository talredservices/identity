FROM node:22-alpine AS build

WORKDIR /app
RUN corepack enable

# Workspace manifests, Nuxt config, and dependency patches must be present
# before postinstall runs nuxt prepare.
COPY . .
RUN pnpm install --frozen-lockfile
RUN pnpm build
# Nitro externalizes Zod in server route chunks; include it in the standalone
# output so the final image can resolve the runtime imports.
RUN if [ ! -e /app/.output/server/node_modules/zod ]; then \
      mkdir -p /app/.output/server/node_modules && \
      cp -aL /app/node_modules/zod /app/.output/server/node_modules/zod; \
    fi

FROM node:22-alpine

WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000

COPY --from=build /app/.output ./.output

CMD ["node", ".output/server/index.mjs"]
