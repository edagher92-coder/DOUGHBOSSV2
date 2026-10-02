import { describe, expect, it } from "vitest";
import { parseCsv, parseCsvObjects } from "./helpers/csv";

describe("parseCsv", () => {
  it("parses simple rows and ignores a trailing newline", () => {
    expect(parseCsv("a,b\n1,2\n")).toEqual([
      ["a", "b"],
      ["1", "2"],
    ]);
  });

  it("handles quoted commas, escaped quotes and newlines inside quotes", () => {
    expect(parseCsv('x,y\n"hello, world","say ""hi"""\n"line1\nline2",z')).toEqual([
      ["x", "y"],
      ["hello, world", 'say "hi"'],
      ["line1\nline2", "z"],
    ]);
  });

  it("handles CRLF and keeps empty fields", () => {
    expect(parseCsv("a,b,c\r\n1,,3\r\n")).toEqual([
      ["a", "b", "c"],
      ["1", "", "3"],
    ]);
  });

  it("throws on an unterminated quote", () => {
    expect(() => parseCsv('a,"oops')).toThrow(/unterminated/);
  });
});

describe("parseCsvObjects", () => {
  it("keys rows by header", () => {
    expect(parseCsvObjects("h1,h2\nv1,v2")).toEqual([{ h1: "v1", h2: "v2" }]);
  });

  it("fails loudly on a ragged row", () => {
    expect(() => parseCsvObjects("a,b\n1,2,3")).toThrow(/row 2 has 3 cells, expected 2/);
  });

  it("returns [] for empty input", () => {
    expect(parseCsvObjects("")).toEqual([]);
  });
});
