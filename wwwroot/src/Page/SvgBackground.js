import React from 'react';
import { Rect } from 'react-konva';
import useImage from 'use-image';
import { normalizeImageAssetSrc } from '../Assets/imageSource';

function SvgBackground({ backgroundUrl, width, height}) {
  const normalizedBackgroundUrl = normalizeImageAssetSrc(backgroundUrl);
  if(normalizedBackgroundUrl.indexOf('#')>-1){// Comment translated to English.
    return (
      <Rect
        x={0}
        y={0}
        zIndex={-10000}
        width={width}
        height={height}
        fill={normalizedBackgroundUrl}
        id="canvasBackground"
        draggable={false}
        >
      </Rect>
    )
  }else{// Comment translated to English.
    // create image of image src
    const [background] = useImage(normalizedBackgroundUrl);
    let widthRatio = 1, heightRatio = 1;
    if(background !== undefined) {
      widthRatio = width / background.width;
      heightRatio = height / background.height;
    }
    return (
      <Rect
        x={0}
        y={0}
        zIndex={-10000}
        width={width}
        height={height}
        fillPatternImage={background}
        fillPatternRepeat={'no-repeat'}
        fillPatternScaleX={widthRatio}
        fillPatternScaleY={heightRatio}
        id="canvasBackground"
        draggable={false}
        >
      </Rect>
    )
  }
}

export default SvgBackground;
