import "dotenv/config";
import express from "express";
import compression from "compression";
import { createServer } from "http";
import crypto from "node:crypto";
import net from "net";
import { createExpressMiddleware } from "@trpc/server/adapters/express";
import { registerOAuthRoutes } from "./oauth";
import { registerStorageProxy } from "./storageProxy";
import { appRouter } from "../routers";
import { createContext } from "./context";
import { serveStatic, setupVite } from "./vite";
import { registerLegacyApi } from "../legacyApi";

function isPortAvailable(port: number): Promise<boolean> {
  return new Promise(resolve => {
    const server = net.createServer();
    server.listen(port, () => {
      server.close(() => resolve(true));
    });
    server.on("error", () => resolve(false));
  });
}

async function findAvailablePort(startPort: number = 3000): Promise<number> {
  for (let port = startPort; port < startPort + 20; port++) {
    if (await isPortAvailable(port)) {
      return port;
    }
  }
  throw new Error(`No available port found starting from ${startPort}`);
}

async function startServer() {
  const app = express();
  const server = createServer(app);
  app.use(compression({ threshold: 1024 }));
  app.use((req, res, next) => {
    const started = performance.now();
    const requestId = req.headers["x-request-id"]?.toString() || crypto.randomUUID();
    res.setHeader("X-Request-Id", requestId);
    res.on("finish", () => {
      if (process.env.PERF_LOG !== "1") return;
      console.info(JSON.stringify({
        perf: "http.request",
        requestId,
        method: req.method,
        path: req.path,
        status: res.statusCode,
        durationMs: Math.round((performance.now() - started) * 100) / 100,
      }));
    });
    next();
  });
  const jsonLimit = process.env.JSON_BODY_LIMIT || "1mb";
  const formLimit = process.env.FORM_BODY_LIMIT || "1mb";
  app.use(express.json({ limit: jsonLimit }));
  app.use(express.urlencoded({ limit: formLimit, extended: true }));
  registerStorageProxy(app);
  registerOAuthRoutes(app);
  // Compatibility API for the original api3.php UI. It must run before Vite's fallback.
  registerLegacyApi(app);
  // tRPC API
  app.use(
    "/api/trpc",
    createExpressMiddleware({
      router: appRouter,
      createContext,
    })
  );
  // development mode uses Vite, production mode uses static files
  if (process.env.NODE_ENV === "development") {
    await setupVite(app, server);
  } else {
    app.use((req, res, next) => {
      if (/\.[a-f0-9]{8,}\.(?:js|css|woff2?|png|jpg|jpeg|svg|webp)$/i.test(req.path)) {
        res.setHeader("Cache-Control", "public, max-age=31536000, immutable");
      }
      next();
    });
    serveStatic(app);
  }

  const preferredPort = parseInt(process.env.PORT || "3000");
  const port = await findAvailablePort(preferredPort);

  if (port !== preferredPort) {
    console.log(`Port ${preferredPort} is busy, using port ${port} instead`);
  }

  server.listen(port, () => {
    console.log(`Server running on http://localhost:${port}/`);
  });
}

startServer().catch(console.error);
