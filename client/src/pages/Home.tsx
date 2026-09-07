import { useEffect, useRef } from "react";
import legacyHtml from "../legacy.html?raw";

/**
 * Mounts the original api3.php document directly into the page. Keeping the
 * legacy document out of an iframe preserves its native viewport, fixed
 * controls, navigation drawer, responsive breakpoints, and scroll behavior.
 */
export default function Home() {
  const hostRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const host = hostRef.current;
    if (!host) return;

    const isHmr = Boolean(import.meta.hot);
    const runtimeWindow = window as Window & { __ldLegacyRuntime?: boolean };
    if (isHmr && runtimeWindow.__ldLegacyRuntime) return;
    runtimeWindow.__ldLegacyRuntime = true;

    const explicitViewMode = localStorage.getItem("fp_view_mode");
    const source = new DOMParser().parseFromString(legacyHtml, "text/html");
    const injectedHeadNodes: Node[] = [];

    source.head.querySelectorAll("style, link[rel=\"stylesheet\"]").forEach(node => {
      const clone = document.importNode(node, true);
      document.head.appendChild(clone);
      injectedHeadNodes.push(clone);
    });

    document.title = source.title || "FunPass API — Control Panel";

    const bodyNodes = Array.from(source.body.childNodes);
    const scripts = bodyNodes.filter(
      node => node.nodeType === Node.ELEMENT_NODE && (node as Element).tagName === "SCRIPT",
    ) as HTMLScriptElement[];
    const fragment = document.createDocumentFragment();

    bodyNodes.forEach(node => {
      if (node.nodeType === Node.ELEMENT_NODE && (node as Element).tagName === "SCRIPT") return;
      fragment.appendChild(document.importNode(node, true));
    });
    host.replaceChildren(fragment);

    scripts.forEach(sourceScript => {
      const script = document.createElement("script");
      Array.from(sourceScript.attributes).forEach(attribute => {
        script.setAttribute(attribute.name, attribute.value);
      });
      script.textContent = sourceScript.textContent || "";
      host.appendChild(script);
    });

    // The source keeps an explicit user choice. When there is no choice yet,
    // select its own mobile mode on a narrow viewport so the original layout
    // does not render desktop columns inside a phone-sized screen.
    const legacyWindow = window as Window & {
      setViewMode?: (mode: string) => void;
    };
    const sourceSetViewMode = legacyWindow.setViewMode;
    if (sourceSetViewMode) {
      legacyWindow.setViewMode = mode => {
        sourceSetViewMode(mode);
        localStorage.setItem("fp_view_mode_user", "1");
      };
      if (!localStorage.getItem("fp_view_mode_user") && window.matchMedia("(max-width: 650px)").matches) {
        sourceSetViewMode("mob");
      }
    }

    return () => {
      // Inline legacy scripts create global bindings. During Vite HMR, keep the
      // existing runtime alive instead of evaluating those bindings a second time.
      if (isHmr) return;
      host.replaceChildren();
      injectedHeadNodes.forEach(node => node.parentNode?.removeChild(node));
      runtimeWindow.__ldLegacyRuntime = false;
    };
  }, []);

  return <div ref={hostRef} className="legacy-host" aria-label="FunPass API" />;
}
