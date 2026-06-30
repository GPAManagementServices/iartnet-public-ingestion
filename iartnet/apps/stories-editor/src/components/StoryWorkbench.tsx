import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEventHandler } from "react";
import { sendDirty, sendSave } from "../integration/hostBridge";
import { normalizeStoryForExport } from "../story/storyExport";
import Accordion from "react-bootstrap/Accordion";
import Alert from "react-bootstrap/Alert";
import { IconButton } from "./IconButton";
import Card from "react-bootstrap/Card";
import Container from "react-bootstrap/Container";
import Form from "react-bootstrap/Form";
import Navbar from "react-bootstrap/Navbar";
import Tab from "react-bootstrap/Tab";
import Tabs from "react-bootstrap/Tabs";
import type { SectionRow } from "../story/sectionRow";
import { rowsFromSections, sectionsFromRows, storyWithWireSections } from "../story/sectionRow";
import { createDefaultStory } from "../story/defaults";
import { parseStoryJson } from "../story/parseStory";
import { saveJsonTextToFilesystem, serializeStoryForFile, suggestedJsonFilename } from "../story/jsonFilesystem";
import type { TStoriesExtJson, TStoriesTypeData, TStoryHeaderType } from "../types/story";
import { MetadataCard } from "./MetadataCard";
import { HeaderEditor } from "./HeaderEditor";
import { SectionsEditor } from "./SectionsEditor";
import { supplementaryMerge } from "./supplementaryHelpers";
import { SupplementaryListsEditor } from "./SupplementaryListsEditor";
import { FORM_GROUP_GAP, FORM_LABEL } from "./formStyles";

function headerAccordionSubtitle(h: TStoryHeaderType): string {
  const t = (h.Title ?? "").trim();
  if (t) return t;
  const st = (h.SubTitle ?? "").trim();
  if (st) return st;
  const c = (h.Chip ?? "").trim();
  if (c) return c;
  return "(senza titolo)";
}

export interface StoryWorkbenchProps {
  embedded?: boolean;
  initialStory?: TStoriesTypeData;
}

export function StoryWorkbench({ embedded = false, initialStory }: StoryWorkbenchProps = {}) {
  const seed = useMemo(() => initialStory ?? createDefaultStory(), [initialStory]);
  const [story, setStory] = useState<TStoriesTypeData>(seed);
  const [sectionRows, setSectionRowsState] = useState<SectionRow[]>(() => rowsFromSections(seed.ext_json.sections));
  const [baselineStory, setBaselineStory] = useState(() => JSON.stringify(seed));

  const [jsonText, setJsonText] = useState(() => JSON.stringify(seed, null, 2));
  const [jsonError, setJsonError] = useState<string | null>(null);
  const jsonFileInputRef = useRef<HTMLInputElement>(null);

  const serializeToText = useCallback((s: TStoriesTypeData) => {
    setJsonText(JSON.stringify(s, null, 2));
    setJsonError(null);
  }, []);

  const applyStory = useCallback(
    (s: TStoriesTypeData, syncJson = true) => {
      const rows = rowsFromSections(s.ext_json.sections);
      setStory(s);
      setSectionRowsState(rows);
      if (syncJson) serializeToText(storyWithWireSections(s, rows));
      setJsonError(null);
      setBaselineStory(JSON.stringify(storyWithWireSections(s, rows)));
    },
    [serializeToText],
  );

  useEffect(() => {
    if (!embedded) {
      return;
    }
    const current = JSON.stringify(storyWithWireSections(story, sectionRows));
    sendDirty(current !== baselineStory);
  }, [embedded, story, sectionRows, baselineStory]);

  const onRowsChange = (rows: SectionRow[]) => {
    setSectionRowsState(rows);
    setStory((prev) => ({
      ...prev,
      updated_at: new Date().toISOString(),
      ext_json: {
        ...prev.ext_json,
        sections: sectionsFromRows(rows),
      },
    }));
  };

  const mergeExtJson = useCallback((partial: Partial<TStoriesExtJson>) => {
    setStory((prev) => ({
      ...prev,
      updated_at: new Date().toISOString(),
      ext_json: supplementaryMerge(prev.ext_json, partial),
    }));
  }, []);

  const applyJsonText = useCallback(
    (text: string) => {
      const parsed = parseStoryJson(text);
      if (parsed.ok === false) {
        setJsonError(parsed.error);
        return;
      }
      applyStory(parsed.value, false);
    },
    [applyStory],
  );

  const applyJsonFromClipboard = useCallback(() => {
    applyJsonText(jsonText);
  }, [applyJsonText, jsonText]);

  const onJsonFilePicked: ChangeEventHandler<HTMLInputElement> = (e) => {
    const input = e.currentTarget;
    const file = input.files?.[0];
    input.value = "";
    if (!file) return;
    void file
      .text()
      .then((text) => {
        setJsonText(text);
        applyJsonText(text);
      })
      .catch(() => setJsonError("Lettura file non riuscita"));
  };

  const saveJsonFile = useCallback(() => {
    void (async () => {
      try {
        const text = serializeStoryForFile(storyWithWireSections(story, sectionRows));
        const name = suggestedJsonFilename(story);
        await saveJsonTextToFilesystem(text, name);
        setJsonError(null);
      } catch {
        setJsonError("Salvataggio file non riuscito");
      }
    })();
  }, [story, sectionRows]);

  const refreshJsonPreview = () => {
    serializeToText(storyWithWireSections(story, sectionRows));
  };

  const saveToHost = useCallback(() => {
    const wired = storyWithWireSections(story, sectionRows);
    const normalized = normalizeStoryForExport(wired);
    sendSave(normalized.ext_json, normalized.updated_at);
    setBaselineStory(JSON.stringify(wired));
    sendDirty(false);
  }, [story, sectionRows]);

  return (
    <>
      <Navbar bg="dark" variant="dark" expand="lg" className="mb-4">
        <Container fluid className="d-flex align-items-center justify-content-between">
          <Navbar.Brand>Stories Editor</Navbar.Brand>
          {embedded ? (
            <IconButton type="button" variant="success" size="sm" icon="check-lg" onClick={saveToHost}>
              Salva nella narrazione
            </IconButton>
          ) : null}
        </Container>
      </Navbar>
      <Container fluid className="pb-5 px-4">
        <Tabs defaultActiveKey="body" id="story-tabs" className="mb-3">
          <Tab eventKey="body" title="Contenuti">
            {!embedded ? (
              <MetadataCard
                story={story}
                onChange={(next) => {
                  setStory(next);
                }}
              />
            ) : null}

            <Accordion className="mb-4" defaultActiveKey="ext-json-header" alwaysOpen>
              <Accordion.Item eventKey="ext-json-header">
                <Accordion.Header>
                  <span className="fw-semibold me-2">Header · ext_json.Header</span>
                  <span className="text-muted small fw-normal">
                    {story.ext_json.Header.Layout} · {headerAccordionSubtitle(story.ext_json.Header)}
                  </span>
                </Accordion.Header>
                <Accordion.Body className="pt-2 pb-2">
                  <HeaderEditor
                    value={story.ext_json.Header}
                    onChange={(Header) =>
                      mergeExtJson({
                        Header,
                      })
                    }
                  />
                </Accordion.Body>
              </Accordion.Item>
            </Accordion>

            <Card className="mb-4">
              <Card.Header className="small py-2">Sezioni · ext_json.sections</Card.Header>
              <Card.Body className="py-2">
                <SectionsEditor rows={sectionRows} onRowsChange={onRowsChange} />
              </Card.Body>
            </Card>

            <SupplementaryListsEditor
              ext={story.ext_json}
              sections={sectionsFromRows(sectionRows)}
              onMerge={mergeExtJson}
            />
          </Tab>

          {!embedded ? (
          <Tab eventKey="json" title="JSON">
            <Card>
              <Card.Header className="small py-2">Import / Export · story completa (TStoriesTypeData)</Card.Header>
              <Card.Body className="py-2">
                {jsonError ? (
                  <Alert variant="danger" dismissible onClose={() => setJsonError(null)}>
                    {jsonError}
                  </Alert>
                ) : null}
                <Form.Group className={FORM_GROUP_GAP}>
                  <Form.Label htmlFor="story-json-area" className={FORM_LABEL}>
                    Area JSON (TStoriesTypeData)
                  </Form.Label>
                  <Form.Control
                    id="story-json-area"
                    as="textarea"
                    rows={18}
                    size="sm"
                    className="font-monospace small"
                    spellCheck={false}
                    value={jsonText}
                    onChange={(e) => setJsonText(e.target.value)}
                  />
                </Form.Group>
                <input ref={jsonFileInputRef} type="file" accept=".json,application/json" className="d-none" aria-label="Carica da file" onChange={onJsonFilePicked} />
                <div className="d-flex flex-wrap gap-2">
                  <IconButton type="button" variant="outline-secondary" size="sm" icon="folder2-open" onClick={() => jsonFileInputRef.current?.click()}>
                    Carica da file…
                  </IconButton>
                  <IconButton
                    type="button"
                    variant="outline-secondary"
                    size="sm"
                    icon="download"
                    onClick={saveJsonFile}
                    title="Salva il modello corrente (come nell’editor), non il testo non applicato nell’area."
                  >
                    Salva su file…
                  </IconButton>
                  <IconButton type="button" variant="secondary" size="sm" icon="arrow-repeat" onClick={refreshJsonPreview}>
                    Sincronizza testo dall&apos;editor
                  </IconButton>
                  <IconButton type="button" variant="primary" size="sm" icon="check-lg" onClick={applyJsonFromClipboard}>
                    Applica JSON
                  </IconButton>
                  <IconButton type="button" variant="outline-danger" size="sm" icon="arrow-counterclockwise" onClick={() => applyStory(createDefaultStory())}>
                    Reset modello
                  </IconButton>
                  <IconButton
                    type="button"
                    variant="outline-danger"
                    size="sm"
                    icon="code-square"
                    onClick={() =>
                      // open model window to https://jsonformatter.curiousconcept.com/
                      window.open("https://jsonformatter.curiousconcept.com/", "_blank") as Window
                    }
                  >
                    Riformatta JSON
                  </IconButton>
                </div>
              </Card.Body>
            </Card>
          </Tab>
          ) : null}
        </Tabs>
      </Container>
    </>
  );
}
