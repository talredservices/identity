FROM node:24-alpine AS build

WORKDIR /app
RUN corepack enable

# Build the shared Identity Console with the exact consumer-owned layers
# supplied as named contexts by the staging image workflow.
COPY . .
COPY --from=talred /integrations/identity-hosted-auth/ /app/consumer-integrations/talred/
COPY --from=starter /integrations/identity-hosted-auth/ /app/consumer-integrations/starter/

ARG IDENTITY_HOSTED_AUTH_INTEGRATIONS=/app/consumer-integrations/starter,/app/consumer-integrations/talred
ENV IDENTITY_HOSTED_AUTH_INTEGRATIONS=${IDENTITY_HOSTED_AUTH_INTEGRATIONS}

RUN pnpm install --frozen-lockfile && pnpm build
RUN if [ ! -e /app/.output/server/node_modules/zod ]; then \
      mkdir -p /app/.output/server/node_modules && \
      cp -aL /app/node_modules/zod /app/.output/server/node_modules/zod; \
    fi

FROM node:24-alpine

WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000

COPY --from=build /app/.output ./.output

CMD ["node", ".output/server/index.mjs"]
