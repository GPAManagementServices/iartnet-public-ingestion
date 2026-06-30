import { Accordion, Form } from "react-bootstrap";
import { STORY_HEADER_LAYOUTS, type TStoryHeaderType, type TStoryImageType } from "../types/story";
import { DEFAULT_HEADER_FONT_COLOR, isStoryImageEmpty, resolveHeaderFontColor, resolveHeaderLayoutTheme } from "../story/defaults";
import {
  normalizeSeoSlugInput,
  seoSlugMatchesTitle,
  titleToSeoSlug,
} from "../story/seoSlug";
import { useSingleAccordionActiveKey } from "../hooks/useAccordionActiveKeys";
import { FORM_ADJACENT_ROW, FORM_COLOR_CONTROL, FORM_GROUP_GAP, FORM_LABEL, FORM_SELECT } from "./formStyles";
import { IconButton } from "./IconButton";
import { HeaderIiifImageFields } from "./fields/HeaderIiifImageFields";

type Props = {
  value: TStoryHeaderType;
  onChange: (next: TStoryHeaderType) => void;
};

const layouts = STORY_HEADER_LAYOUTS;
const HEADER_IMAGE_ACCORDION_KEY = "header-image";
const INDEX_IMAGE_ACCORDION_KEY = "index-image";
const SEO_ACCORDION_KEY = "header-seo";

function storyImageAccordionSubtitle(image: TStoryImageType): string {
  const url = image.URL.trim();
  if (!url) return "(nessuna immagine)";
  if (url.length <= 72) return url;
  return `${url.slice(0, 69)}…`;
}

function headerSeoSubtitle(seo: TStoryHeaderType["SEO"]): string {
  const slug = seo?.slug?.trim();
  return slug || "(nessuno slug)";
}

function seoFromSlugInput(raw: string): TStoryHeaderType["SEO"] {
  const slug = normalizeSeoSlugInput(raw);
  return slug ? { slug } : null;
}

export function HeaderEditor({ value, onChange }: Props) {
  const headerImageAccordion = useSingleAccordionActiveKey(HEADER_IMAGE_ACCORDION_KEY, false);
  const indexImageAccordion = useSingleAccordionActiveKey(INDEX_IMAGE_ACCORDION_KEY, false);
  const seoAccordion = useSingleAccordionActiveKey(SEO_ACCORDION_KEY, false);
  const showImage = value.Layout !== "None";
  const image =
    value.Image ??
    ({
      URL: "",
      Caption: null,
      bgColor: null,
    } as NonNullable<TStoryHeaderType["Image"]>);
  const indexImage =
    value.IndexImage ??
    ({
      URL: "",
      Caption: null,
      bgColor: null,
    } as NonNullable<TStoryHeaderType["IndexImage"]>);
  const seoSlug = value.SEO?.slug ?? "";
  const titleTrimmed = (value.Title ?? "").trim();
  const showSeoTitleMismatch =
    seoSlug.length > 0 && titleTrimmed.length > 0 && !seoSlugMatchesTitle(seoSlug, value.Title);

  return (
    <>
      <div className={`${FORM_ADJACENT_ROW} ${FORM_GROUP_GAP} mw-100`}>
        <Form.Group className="mb-0 flex-shrink-0" controlId="header-layout">
          <Form.Label className={FORM_LABEL}>Layout</Form.Label>
          <Form.Select
            className={FORM_SELECT}
            size="sm"
            value={value.Layout}
            onChange={(e) => {
              const Layout = e.target.value as TStoryHeaderType["Layout"];
              const next: TStoryHeaderType = { ...value, Layout };
              if (Layout === "None") {
                next.Image = null;
              } else if (!next.Image) {
                next.Image = { URL: "" };
              }
              onChange(next);
            }}
          >
            {layouts.map((l) => (
              <option key={l} value={l}>
                {l}
              </option>
            ))}
          </Form.Select>
        </Form.Group>
        <Form.Group
          className="mb-0 flex-shrink-0"
          style={{ width: "min(14rem, 100%)" }}
          controlId="header-font-color"
        >
          <Form.Label className={FORM_LABEL}>FontColor</Form.Label>
          <Form.Control
            size="sm"
            className={FORM_COLOR_CONTROL}
            style={{ maxWidth: "min(100%, 14rem)", minWidth: "8ch" }}
            value={resolveHeaderFontColor(value.FontColor)}
            placeholder={DEFAULT_HEADER_FONT_COLOR}
            onChange={(e) =>
              onChange({
                ...value,
                FontColor: resolveHeaderFontColor(e.target.value),
              })
            }
          />
        </Form.Group>
        <Form.Group className="mb-0 flex-shrink-0" style={{ width: "min(12rem, 100%)" }} controlId="header-chip">
          <Form.Label className={FORM_LABEL}>Chip</Form.Label>
          <Form.Control
            size="sm"
            className="mw-100"
            value={value.Chip ?? ""}
            onChange={(e) =>
              onChange({
                ...value,
                Chip: e.target.value === "" ? null : e.target.value,
              })
            }
          />
        </Form.Group>
        <Form.Group className="mb-0 flex-shrink-0 d-flex align-items-end" controlId="header-layout-theme">
          <Form.Check
            type="switch"
            className="small mb-1"
            label="Dark"
            checked={resolveHeaderLayoutTheme(value.HeaderLayoutTheme) === "Dark"}
            onChange={(e) =>
              onChange({
                ...value,
                HeaderLayoutTheme: e.target.checked ? "Dark" : "Light",
              })
            }
          />
        </Form.Group>
      </div>
      <Form.Group className={`mb-0 ${FORM_GROUP_GAP} mw-100`} controlId="header-title">
        <Form.Label className={FORM_LABEL}>Titolo</Form.Label>
        <Form.Control
          size="sm"
          className="mw-100"
          value={value.Title ?? ""}
          onChange={(e) =>
            onChange({
              ...value,
              Title: e.target.value === "" ? null : e.target.value,
            })
          }
        />
      </Form.Group>
      <Form.Group className={`mb-0 ${FORM_GROUP_GAP} mw-100`} controlId="header-subtitle">
        <Form.Label className={FORM_LABEL}>SubTitle</Form.Label>
        <Form.Control
          as="textarea"
          rows={2}
          size="sm"
          className="mw-100"
          style={{ resize: "none" }}
          value={value.SubTitle ?? ""}
          onChange={(e) => {
            const lines = e.target.value.split("\n");
            const SubTitle = lines.length > 2 ? lines.slice(0, 2).join("\n") : e.target.value;
            onChange({
              ...value,
              SubTitle: SubTitle === "" ? null : SubTitle,
            });
          }}
        />
      </Form.Group>
      {showImage ? (
        <Accordion
          className="mt-2 mb-0"
          activeKey={headerImageAccordion.activeKey}
          onSelect={headerImageAccordion.onSelect}
        >
          <Accordion.Item eventKey={HEADER_IMAGE_ACCORDION_KEY}>
            <Accordion.Header className="py-1">
              <span className={`${FORM_LABEL} me-2`}>Header: immagine</span>
              <span className={`${FORM_LABEL} fw-normal`}>{storyImageAccordionSubtitle(image)}</span>
            </Accordion.Header>
            <Accordion.Body className="pt-2 pb-2">
              <div role="group" aria-label="Header: immagine">
                <HeaderIiifImageFields
                  prefix="Header"
                  value={image}
                  showLegend={false}
                  onChange={(Image) => onChange({ ...value, Image })}
                />
              </div>
            </Accordion.Body>
          </Accordion.Item>
        </Accordion>
      ) : null}
      <Accordion
        className="mt-2 mb-0"
        activeKey={indexImageAccordion.activeKey}
        onSelect={indexImageAccordion.onSelect}
      >
        <Accordion.Item eventKey={INDEX_IMAGE_ACCORDION_KEY}>
          <Accordion.Header className="py-1">
            <span className={`${FORM_LABEL} me-2`}>IndexImage: immagine</span>
            <span className={`${FORM_LABEL} fw-normal`}>{storyImageAccordionSubtitle(indexImage)}</span>
          </Accordion.Header>
          <Accordion.Body className="pt-2 pb-2">
            <div className="d-flex justify-content-end mb-2">
              <IconButton
                type="button"
                variant="outline-secondary"
                size="sm"
                icon="copy"
                className="py-0 px-1"
                title="Copia da Image"
                aria-label="Copia da Image"
                disabled={isStoryImageEmpty(value.Image)}
                onClick={() =>
                  onChange({
                    ...value,
                    IndexImage: value.Image ? { ...value.Image } : null,
                  })
                }
              >
                Copia da Image
              </IconButton>
            </div>
            <div role="group" aria-label="IndexImage: immagine">
              <HeaderIiifImageFields
                prefix="IndexImage"
                value={indexImage}
                showLegend={false}
                onChange={(IndexImage) =>
                  onChange({
                    ...value,
                    IndexImage: isStoryImageEmpty(IndexImage) ? null : IndexImage,
                  })
                }
              />
            </div>
          </Accordion.Body>
        </Accordion.Item>
      </Accordion>
      <Accordion
        className="mt-2 mb-0"
        activeKey={seoAccordion.activeKey}
        onSelect={seoAccordion.onSelect}
      >
        <Accordion.Item eventKey={SEO_ACCORDION_KEY}>
          <Accordion.Header className="py-1">
            <span className={`${FORM_LABEL} me-2`}>SEO</span>
            <span className={`${FORM_LABEL} fw-normal`}>{headerSeoSubtitle(value.SEO)}</span>
          </Accordion.Header>
          <Accordion.Body className="pt-2 pb-2">
            <Form.Group className="mb-0" controlId="header-seo-slug">
              <div className="d-flex align-items-center justify-content-between gap-2 mb-1">
                <Form.Label className={FORM_LABEL}>Slug</Form.Label>
                <IconButton
                  type="button"
                  variant="outline-secondary"
                  size="sm"
                  icon="arrow-repeat"
                  className="py-0 px-1"
                  title="Genera da Titolo"
                  aria-label="Genera da Titolo"
                  disabled={!titleTrimmed}
                  onClick={() =>
                    onChange({
                      ...value,
                      SEO: seoFromSlugInput(titleToSeoSlug(titleTrimmed)),
                    })
                  }
                />
              </div>
              <Form.Control
                size="sm"
                className="mw-100 font-monospace"
                placeholder="mozart-and-the-magic-flute"
                value={seoSlug}
                onChange={(e) =>
                  onChange({
                    ...value,
                    SEO: e.target.value.trim() ? { slug: e.target.value } : null,
                  })
                }
                onBlur={(e) =>
                  onChange({
                    ...value,
                    SEO: seoFromSlugInput(e.target.value),
                  })
                }
              />
              {showSeoTitleMismatch ? (
                <Form.Text className="text-muted">
                  Diverso dal titolo — usa Genera da Titolo per allineare.
                </Form.Text>
              ) : null}
            </Form.Group>
          </Accordion.Body>
        </Accordion.Item>
      </Accordion>
    </>
  );
}
