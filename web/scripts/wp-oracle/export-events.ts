/**
 * Export of the analytics taxonomy for the DoughBoss Growth companion (WP-03).
 *
 * Reads src/lib/analytics/events.ts (the single source of truth for event names and parameters) and writes
 * doughboss-growth/content/events.json. The ES5 dispatcher (doughboss-growth/public/js/dbgr-datalayer.js) refuses
 * every event name that is not in that file and drops every parameter that is not in the event's allow-list, so the
 * browser can never push something the TypeScript taxonomy does not define.
 *
 * Parameter types are read from the EventParams interface with the TypeScript compiler API (syntax only; nothing
 * is type-checked or executed):
 *   string literal / numeric literal unions (also through a type alias such as StoreSlug or ContactSurface) -> enum
 *   string                                                                                                  -> string
 *   number                                                                                                  -> integer (money is integer cents, counts are whole numbers, events.ts rule 3)
 * Anything else makes the export fail loudly instead of guessing.
 *
 * Regenerate from the repository root with:
 *   NODE_PATH=web/node_modules web/node_modules/.bin/tsx web/scripts/wp-oracle/export-events.ts
 *
 * Pass --check to compare instead of write (exit 1 when the file on disk differs from a fresh export).
 */
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import ts from "typescript";
import { EVENT_NAMES } from "../../src/lib/analytics/events";

const here = dirname(fileURLToPath(import.meta.url));
const webRoot = resolve(here, "../..");
const eventsPath = resolve(webRoot, "src/lib/analytics/events.ts");
const menuTypesPath = resolve(webRoot, "src/types/menu.ts");
const outPath = resolve(webRoot, "../doughboss-growth/content/events.json");

export type ParamType =
  | { type: "enum"; values: Array<string | number> }
  | { type: "string" }
  | { type: "integer" };

export type ParamSpec = ParamType & { optional: boolean };

export interface EventsExport {
  schema_version: 1;
  source: string;
  source_sha256: string;
  generated_by: string;
  event_names: string[];
  events: Record<string, { params: Record<string, ParamSpec> }>;
}

const PARAM_NAME = /^[a-z][a-z0-9_]*$/;
/** dataLayer keys the dispatcher itself writes or that Tag Manager reserves. A taxonomy parameter may never use one. */
const RESERVED_PARAMS = new Set(["event", "event_id", "consent", "gtm", "eventCallback", "eventTimeout"]);

/**
 * Events that belong to the cancelled 3D hero ("3d here is a no", 2 Oct 2026). They stay in events.ts for the shelved
 * Next.js kit but must never reach the WordPress companion, which ships no 3D, WebGL or frame-player code.
 */
const CANCELLED_EVENTS = new Set(["hero_explore"]);

function parse(path: string): ts.SourceFile {
  return ts.createSourceFile(path, readFileSync(path, "utf8"), ts.ScriptTarget.ES2022, true, ts.ScriptKind.TS);
}

/** type alias name -> its type node, across the given files. */
function aliasIndex(files: ts.SourceFile[]): Map<string, ts.TypeNode> {
  const index = new Map<string, ts.TypeNode>();
  for (const file of files) {
    for (const statement of file.statements) {
      if (ts.isTypeAliasDeclaration(statement)) index.set(statement.name.text, statement.type);
    }
  }
  return index;
}

function literalValue(node: ts.TypeNode): string | number | null {
  if (!ts.isLiteralTypeNode(node)) return null;
  const literal = node.literal;
  if (ts.isStringLiteral(literal)) return literal.text;
  if (ts.isNumericLiteral(literal)) return Number(literal.text);
  return null;
}

function toParamType(node: ts.TypeNode, aliases: Map<string, ts.TypeNode>, where: string, depth = 0): ParamType {
  if (depth > 8) throw new Error(`${where}: type alias nesting too deep`);
  if (ts.isParenthesizedTypeNode(node)) return toParamType(node.type, aliases, where, depth + 1);
  if (node.kind === ts.SyntaxKind.StringKeyword) return { type: "string" };
  if (node.kind === ts.SyntaxKind.NumberKeyword) return { type: "integer" };
  const single = literalValue(node);
  if (single !== null) return { type: "enum", values: [single] };
  if (ts.isTypeReferenceNode(node) && ts.isIdentifier(node.typeName)) {
    const target = aliases.get(node.typeName.text);
    if (!target) throw new Error(`${where}: cannot resolve type ${node.typeName.text}`);
    return toParamType(target, aliases, where, depth + 1);
  }
  if (ts.isUnionTypeNode(node)) {
    const values: Array<string | number> = [];
    for (const member of node.types) {
      const inner = toParamType(member, aliases, where, depth + 1);
      if (inner.type !== "enum") throw new Error(`${where}: a union may only combine literals (found ${inner.type})`);
      for (const value of inner.values) if (!values.includes(value)) values.push(value);
    }
    return { type: "enum", values };
  }
  throw new Error(`${where}: unsupported type syntax ${ts.SyntaxKind[node.kind]}`);
}

function propertyName(member: ts.PropertySignature, where: string): string {
  if (ts.isIdentifier(member.name) || ts.isStringLiteral(member.name)) return member.name.text;
  throw new Error(`${where}: unsupported property name`);
}

/** Build the export object from the TypeScript sources. Pure: nothing is written. */
export function buildEvents(): EventsExport {
  const eventsFile = parse(eventsPath);
  const menuFile = parse(menuTypesPath);
  const aliases = aliasIndex([eventsFile, menuFile]);

  let paramsInterface: ts.InterfaceDeclaration | undefined;
  for (const statement of eventsFile.statements) {
    if (ts.isInterfaceDeclaration(statement) && statement.name.text === "EventParams") paramsInterface = statement;
  }
  if (!paramsInterface) throw new Error("events.ts: interface EventParams not found");

  const events: EventsExport["events"] = {};
  for (const member of paramsInterface.members) {
    if (!ts.isPropertySignature(member) || !member.type) throw new Error("EventParams: unsupported member");
    const eventName = propertyName(member, "EventParams");
    if (!ts.isTypeLiteralNode(member.type)) throw new Error(`${eventName}: parameters must be an object type literal`);
    const params: Record<string, ParamSpec> = {};
    for (const field of member.type.members) {
      if (!ts.isPropertySignature(field) || !field.type) throw new Error(`${eventName}: unsupported parameter member`);
      const name = propertyName(field, eventName);
      if (!PARAM_NAME.test(name) || RESERVED_PARAMS.has(name)) throw new Error(`${eventName}.${name}: parameter name not allowed`);
      if (name in params) throw new Error(`${eventName}.${name}: duplicate parameter`);
      params[name] = { ...toParamType(field.type, aliases, `${eventName}.${name}`), optional: field.questionToken !== undefined };
    }
    events[eventName] = { params };
  }

  const names = [...EVENT_NAMES] as string[];
  const declared = Object.keys(events);
  const missing = names.filter((name) => !(name in events));
  const extra = declared.filter((name) => !names.includes(name));
  if (missing.length > 0 || extra.length > 0) {
    throw new Error(`EVENT_NAMES and EventParams disagree (missing from EventParams: ${missing.join(",") || "none"}; missing from EVENT_NAMES: ${extra.join(",") || "none"})`);
  }

  const ordered: EventsExport["events"] = {};
  for (const name of names) {
    if (!CANCELLED_EVENTS.has(name)) ordered[name] = events[name] as EventsExport["events"][string];
  }

  return {
    schema_version: 1,
    source: "web/src/lib/analytics/events.ts",
    source_sha256: createHash("sha256").update(readFileSync(eventsPath)).digest("hex"),
    generated_by: "web/scripts/wp-oracle/export-events.ts",
    event_names: Object.keys(ordered),
    events: ordered,
  };
}

/** Pretty JSON: one line per event parameter, one line per short array. Deterministic. */
export function render(data: EventsExport): string {
  const line = (value: unknown): string => JSON.stringify(value).replace(/,(?=\S)/g, ", ").replace(/:(?=\S)/g, ": ");
  const out: string[] = ["{"];
  out.push(`  "schema_version": ${data.schema_version},`);
  out.push(`  "source": ${JSON.stringify(data.source)},`);
  out.push(`  "source_sha256": ${JSON.stringify(data.source_sha256)},`);
  out.push(`  "generated_by": ${JSON.stringify(data.generated_by)},`);
  out.push(`  "event_names": ${line(data.event_names)},`);
  out.push('  "events": {');
  const names = Object.keys(data.events);
  names.forEach((name, eventIndex) => {
    const params = (data.events[name] as EventsExport["events"][string]).params;
    const keys = Object.keys(params);
    out.push(`    ${JSON.stringify(name)}: {`);
    out.push('      "params": {');
    keys.forEach((key, index) => {
      out.push(`        ${JSON.stringify(key)}: ${line(params[key])}${index < keys.length - 1 ? "," : ""}`);
    });
    out.push("      }");
    out.push(`    }${eventIndex < names.length - 1 ? "," : ""}`);
  });
  out.push("  }");
  out.push("}");
  return `${out.join("\n")}\n`;
}

export const EVENTS_JSON_PATH = outPath;

function main(): number {
  const text = render(buildEvents());
  if (process.argv.includes("--check")) {
    const onDisk = existsSync(outPath) ? readFileSync(outPath, "utf8") : null;
    if (onDisk === text) {
      process.stdout.write(`events.json is up to date (${outPath})\n`);
      return 0;
    }
    process.stderr.write(`events.json is ${onDisk === null ? "missing" : "STALE"}: regenerate with tsx web/scripts/wp-oracle/export-events.ts\n`);
    return 1;
  }
  writeFileSync(outPath, text);
  process.stdout.write(`wrote ${outPath}\n`);
  return 0;
}

const invokedDirectly = process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedDirectly) process.exit(main());
