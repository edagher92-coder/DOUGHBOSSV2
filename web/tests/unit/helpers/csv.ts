/**
 * Minimal RFC 4180 CSV reader for tests that validate marketing data files
 * (ad copy lengths, keyword lists, citation lists…). Handles quoted fields,
 * escaped quotes (""), commas and newlines inside quotes, and CRLF.
 * Deliberately tiny: it exists so no test pulls in a dependency.
 */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = "";
  let inQuotes = false;

  const endField = () => {
    row.push(field);
    field = "";
  };
  const endRow = () => {
    endField();
    // Ignore completely blank lines (a trailing newline produces one).
    if (!(row.length === 1 && row[0] === "")) rows.push(row);
    row = [];
  };

  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQuotes) {
      if (c === '"') {
        if (text[i + 1] === '"') {
          field += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        field += c;
      }
      continue;
    }
    if (c === '"') inQuotes = true;
    else if (c === ",") endField();
    else if (c === "\n") endRow();
    else if (c === "\r") {
      if (text[i + 1] === "\n") i++;
      endRow();
    } else field += c;
  }
  if (inQuotes) throw new Error("parseCsv: unterminated quoted field");
  if (field !== "" || row.length > 0) endRow();
  return rows;
}

/** Rows as objects keyed by the header row. Throws on a ragged row so malformed data fails loudly. */
export function parseCsvObjects(text: string): Record<string, string>[] {
  const [header, ...rest] = parseCsv(text);
  if (!header) return [];
  return rest.map((cells, index) => {
    if (cells.length !== header.length) {
      throw new Error(`parseCsvObjects: row ${index + 2} has ${cells.length} cells, expected ${header.length}`);
    }
    return Object.fromEntries(header.map((name, i) => [name.trim(), cells[i] ?? ""]));
  });
}
