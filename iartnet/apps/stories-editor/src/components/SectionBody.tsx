import { Form } from "react-bootstrap";
import {
  type TStorySection,
  type TStoryImageFullScreenType,
  type TStoryInlineImageType,
  type TStoryScrollRevealType,
  type TStorySplitContentType,
  type TStorySplitImageType,
  type TStoryTextIntroType,
  type TStoryIIFAnnotationsGroupType,
} from "../types/story";
import { FORM_ADJACENT_ROW, FORM_GROUP_GAP, FORM_LABEL, FORM_SELECT } from "./formStyles";
import { LinkSchedaFields } from "./fields/LinkSchedaFields";
import { RichTextField } from "./fields/RichTextField";
import { StoryImageFields } from "./fields/StoryImageFields";
import { IIFAnnotationsGroupFields } from "./IIFAnnotationsGroupFields";
import { ScrollRevealFields } from "./ScrollRevealFields";

type Props = {
  section: TStorySection;
  onChange: (next: TStorySection) => void;
};

export function SectionBody({ section, onChange }: Props) {
  switch (section.Kind) {
    case "TextIntro":
    case "InlineText": {
      const s = section as TStoryTextIntroType;
      return <RichTextField label="Text" value={s.Text} onChange={(Text) => onChange({ ...s, Text })} />;
    }
    case "SplitContent": {
      const s = section as TStorySplitContentType;
      return (
        <>
          <RichTextField label="LeftText" value={s.LeftText} onChange={(LeftText) => onChange({ ...s, LeftText })} />
          <RichTextField label="RightText" value={s.RightText} onChange={(RightText) => onChange({ ...s, RightText })} />
        </>
      );
    }
    case "SplitImage": {
      const s = section as TStorySplitImageType;
      const layouts: TStorySplitImageType["Layout"][] = ["Right", "Left", "RightInline", "LeftInline", "RightInlineVertical", "LeftInlineVertical"];
      const mediaTypes: TStorySplitImageType["MediaType"][] = ["Image", "Video"];
      return (
        <>
          <div className={`${FORM_ADJACENT_ROW} ${FORM_GROUP_GAP}`}>
            <Form.Group className="mb-0 flex-shrink-0" controlId="section-split-layout">
              <Form.Label className={FORM_LABEL}>Layout</Form.Label>
              <Form.Select
                className={FORM_SELECT}
                size="sm"
                value={s.Layout}
                onChange={(e) =>
                  onChange({
                    ...s,
                    Layout: e.target.value as TStorySplitImageType["Layout"],
                  })
                }
              >
                {layouts.map((l) => (
                  <option key={l} value={l}>
                    {l}
                  </option>
                ))}
              </Form.Select>
            </Form.Group>
            <Form.Group className="mb-0 flex-shrink-0" controlId="section-split-media-type">
              <Form.Label className={FORM_LABEL}>MediaType</Form.Label>
              <Form.Select
                className={FORM_SELECT}
                size="sm"
                value={s.MediaType ?? "Image"}
                onChange={(e) =>
                  onChange({
                    ...s,
                    MediaType: e.target.value as TStorySplitImageType["MediaType"],
                  })
                }
              >
                {mediaTypes.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </Form.Select>
            </Form.Group>
          </div>
          <RichTextField label="Text" value={s.Text} onChange={(Text) => onChange({ ...s, Text })} />
          <LinkSchedaFields value={s.LinkScheda} onChange={(LinkScheda) => onChange({ ...s, LinkScheda })} />
          <StoryImageFields prefix="Section" value={s.Image} onChange={(Image) => onChange({ ...s, Image })} />
        </>
      );
    }
    case "ScrollReveal": {
      const s = section as TStoryScrollRevealType;
      return <ScrollRevealFields value={s} onChange={onChange} />;
    }
    case "InlineImage": {
      const s = section as TStoryInlineImageType;
      return (
        <>
          <LinkSchedaFields value={s.LinkScheda} onChange={(LinkScheda) => onChange({ ...s, LinkScheda })} />
          <StoryImageFields prefix="Inline" value={s.Image} onChange={(Image) => onChange({ ...s, Image })} />
        </>
      );
    }
    case "ImageFullScreen": {
      const s = section as TStoryImageFullScreenType;
      const positions: TStoryImageFullScreenType["Position"][] = ["BottomLeft", "BottomRight", "TopRight", "TopLeft"];
      const fits: TStoryImageFullScreenType["Fit"][] = ["Cover", "Contain"];
      return (
        <>
          <div className={`${FORM_ADJACENT_ROW} ${FORM_GROUP_GAP}`}>
            <Form.Group className="mb-0 flex-shrink-0" controlId="section-fs-position">
              <Form.Label className={FORM_LABEL}>Position</Form.Label>
              <Form.Select
                className={FORM_SELECT}
                size="sm"
                value={s.Position}
                onChange={(e) =>
                  onChange({
                    ...s,
                    Position: e.target.value as TStoryImageFullScreenType["Position"],
                  })
                }
              >
                {positions.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </Form.Select>
            </Form.Group>
            <Form.Group className="mb-0 flex-shrink-0" controlId="section-fs-fit">
              <Form.Label className={FORM_LABEL}>Fit</Form.Label>
              <Form.Select
                className={FORM_SELECT}
                size="sm"
                value={s.Fit}
                onChange={(e) =>
                  onChange({
                    ...s,
                    Fit: e.target.value as TStoryImageFullScreenType["Fit"],
                  })
                }
              >
                {fits.map((f) => (
                  <option key={f} value={f}>
                    {f}
                  </option>
                ))}
              </Form.Select>
            </Form.Group>
          </div>
          <LinkSchedaFields value={s.LinkScheda} onChange={(LinkScheda) => onChange({ ...s, LinkScheda })} />
          <StoryImageFields prefix="Fullscreen" value={s.Image} onChange={(Image) => onChange({ ...s, Image })} />
        </>
      );
    }
    case "IIFAnnotationsGroup": {
      const s = section as TStoryIIFAnnotationsGroupType;
      return <IIFAnnotationsGroupFields value={s} onChange={onChange} />;
    }
    default:
      return null;
  }
}
