import { useMemo } from 'react'
import { Accordion } from 'react-bootstrap'
import {
  createEmptyScrollRevealParagraph,
  type TStoryScrollRevealParagraphType,
  type TStoryScrollRevealType,
} from '../types/story'
import { useAccordionActiveKeys } from '../hooks/useAccordionActiveKeys'
import { IconButton } from './IconButton'
import { ListEditorToolbar } from './ListEditorToolbar'
import { LinkSchedaFields } from './fields/LinkSchedaFields'
import { RichTextField } from './fields/RichTextField'
import { StoryImageFields } from './fields/StoryImageFields'
import { richTextPlainPreview } from '../story/richText'

type Props = {
  value: TStoryScrollRevealType
  onChange: (next: TStoryScrollRevealType) => void
}

function previewParagraph(paragraph: TStoryScrollRevealParagraphType) {
  const text = richTextPlainPreview(paragraph.Text)
  if (text) return text
  const url = paragraph.Image.URL.trim()
  if (url) return url.length > 48 ? `${url.slice(0, 48)}…` : url
  return '(vuoto)'
}

export function ScrollRevealFields({ value, onChange }: Props) {
  const paragraphs = value.Paragraphs
  const eventKeys = useMemo(
    () => paragraphs.map((_, i) => `scroll-reveal-${i}`),
    [paragraphs],
  )
  const accordion = useAccordionActiveKeys(eventKeys)

  const updateParagraph = (index: number, next: TStoryScrollRevealParagraphType) => {
    onChange({
      ...value,
      Paragraphs: paragraphs.map((paragraph, i) => (i === index ? next : paragraph)),
    })
  }

  return (
    <>
      <ListEditorToolbar
        addLabel="Aggiungi paragrafo"
        onAdd={() =>
          onChange({
            ...value,
            Paragraphs: [...paragraphs, createEmptyScrollRevealParagraph()],
          })
        }
        allOpen={accordion.allOpen}
        onToggleAll={accordion.toggleAll}
        itemCount={paragraphs.length}
      />
      {paragraphs.length > 0 ? (
        <Accordion alwaysOpen activeKey={accordion.activeKeys} onSelect={accordion.onSelect}>
          {paragraphs.map((paragraph, i) => (
            <Accordion.Item eventKey={`scroll-reveal-${i}`} key={`scroll-reveal-${i}`}>
              <Accordion.Header>
                <span className="fw-semibold me-2">Paragrafo #{i + 1}</span>
                <span className="text-muted small fw-normal">{previewParagraph(paragraph)}</span>
              </Accordion.Header>
              <Accordion.Body className="pt-2 pb-2">
                <div className="d-flex justify-content-end mb-2 pb-2 border-bottom">
                  <IconButton
                    type="button"
                    size="sm"
                    variant="outline-danger"
                    icon="trash"
                    disabled={paragraphs.length <= 1}
                    onClick={() =>
                      onChange({
                        ...value,
                        Paragraphs: paragraphs.filter((_, j) => j !== i),
                      })
                    }
                  >
                    Rimuovi
                  </IconButton>
                </div>
                <RichTextField
                  label="Text"
                  value={paragraph.Text}
                  onChange={(Text) => updateParagraph(i, { ...paragraph, Text })}
                />
                <LinkSchedaFields
                  value={paragraph.LinkScheda}
                  onChange={(LinkScheda) => updateParagraph(i, { ...paragraph, LinkScheda })}
                />
                <StoryImageFields
                  prefix={`Reveal-${i + 1}`}
                  value={paragraph.Image}
                  onChange={(Image) => updateParagraph(i, { ...paragraph, Image })}
                />
              </Accordion.Body>
            </Accordion.Item>
          ))}
        </Accordion>
      ) : null}
    </>
  )
}
