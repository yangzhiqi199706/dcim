import React, { useRef, useState, useEffect, Fragment } from 'react';
import { Group, Image, Transformer, Rect } from "react-konva";
import useImage from 'use-image';
import { Html } from "react-konva-utils";

const ConElement = ({ shapeProps, id, isSelected, showSelectionFrame, isHoverHighlighted, onSelect, onChange, onDragMove, toolType, onToolBack }) => {
    if (!shapeProps.moduleJson) {
        return false
    }

    const [imgurl] = useImage((shapeProps.moduleJson.children.length > 0 && (shapeProps.moduleJson.children[0].className === 'Image' || shapeProps.moduleJson.children[0].className === 'videoSwiper')) ? shapeProps.moduleJson.children[0].attrs.image : (shapeProps.src.indexOf('http') > -1 ? '../Images/' + shapeProps.src.split('/Images/')[0] : shapeProps.src));
    const groupRef = useRef();
    const transformRef = useRef();
    const hoverTransformRef = useRef();
    const [newshapeProps, setnewshapeProps] = useState(null);
    const [hoverPulseTick, setHoverPulseTick] = useState(0);
    const [isLockedHover, setIsLockedHover] = useState(false);

    useEffect(() => {
        if ((isSelected || showSelectionFrame || isLockedHover) && transformRef.current) {
            transformRef.current.nodes([groupRef.current]);
            transformRef.current.getLayer().batchDraw();
            setnewshapeProps(shapeProps);
        }
    }, [isSelected, showSelectionFrame, isLockedHover, shapeProps]);

    useEffect(() => {
        if (!isHoverHighlighted) return undefined;
        const timer = window.setInterval(() => {
            setHoverPulseTick((prev) => prev + 1);
        }, 450);
        return () => window.clearInterval(timer);
    }, [isHoverHighlighted]);

    useEffect(() => {
        if (isHoverHighlighted && hoverTransformRef.current) {
            hoverTransformRef.current.nodes([groupRef.current]);
            hoverTransformRef.current.getLayer().batchDraw();
        }
    }, [isHoverHighlighted, shapeProps, hoverPulseTick]);

    useEffect(() => {
        if (transformRef.current != null) {
            transformRef.current.forceUpdate();
        }
        if (hoverTransformRef.current != null) {
            hoverTransformRef.current.forceUpdate();
        }
    });

    const handleDragStart = e => {
        if (e && e.evt) {
            e.evt.__draggingSelection = true;
        }
        onSelect(e);
    };

    const handleDragMove = e => {
        if (onDragMove) {
            onDragMove(e, shapeProps);
        }
    };

    const handleDragEnd = e => {
        onChange({
            ...shapeProps,
            x: e.target.x(),
            y: e.target.y(),
        });
        setnewshapeProps(shapeProps);
    };

    const handeleGroupTransformEnd = () => {
        const group = groupRef.current;
        const scaleX = group.scaleX();
        const scaleY = group.scaleY();
        const rotation = group.rotation();
        const skewX = group.skewX();
        const skewY = group.skewY();
        const newrxy = rotateAroundCenter(group, rotation);
        const nextShapeProps = {
            ...shapeProps,
            scaleX,
            scaleY,
            rotation: newrxy[0],
            x: newrxy[1],
            y: newrxy[2],
            skewX,
            skewY
        };
        onChange(nextShapeProps);
        setnewshapeProps(nextShapeProps);
    };

    const rotatePoint = ({ x, y }, rad) => {
        const rcos = Math.cos(rad);
        const rsin = Math.sin(rad);
        return { x: x * rcos - y * rsin, y: y * rcos + x * rsin };
    };

    function rotateAroundCenter(node, rotation) {
        const topLeft = { x: -node.width() / 2, y: -node.height() / 2 };
        const current = rotatePoint(topLeft, node.rotation());
        const rotated = rotatePoint(topLeft, rotation);
        const dx = rotated.x - current.x;
        const dy = rotated.y - current.y;
        return [rotation, node.x() + dx, node.y() + dy]
    }

    const handleToolChange = (currentToolType) => {
        if (currentToolType !== null && isSelected) {
            const node = groupRef.current;
            switch (currentToolType) {
                case 'copy': onToolBack(newshapeProps, 'copy'); break;
                case 'del': onToolBack(newshapeProps, 'del'); break;
                case 'lock': onToolBack({ ...newshapeProps, draggable: false }, 'lock'); break;
                case 'unlock': onToolBack({ ...newshapeProps, draggable: true }, 'unlock'); break;
                case 'up': onToolBack('', 'up'); node.moveUp(); break;
                case 'down': onToolBack('', 'down'); node.moveDown(); break;
                case 'top': onToolBack('', 'top'); node.moveToTop(); break;
                case 'bottom': onToolBack('', 'bottom'); node.moveToBottom(); node.moveUp(); break;
                default: break;
            }
        }
    };

    const hoverPulseOn = hoverPulseTick % 2 === 0;

    return (
        <Fragment>
            <Group
                id={id}
                onDragStart={handleDragStart}
                onDragMove={handleDragMove}
                onDragEnd={handleDragEnd}
                onMouseEnter={() => {
                    if (shapeProps.draggable === false) {
                        setIsLockedHover(true);
                    }
                }}
                onMouseLeave={() => setIsLockedHover(false)}
                onClick={onSelect}
                onTap={onSelect}
                handleTool={isSelected && handleToolChange(toolType)}
                {...shapeProps}
                ref={groupRef}
                onTransformEnd={() => isSelected && shapeProps.draggable && handeleGroupTransformEnd()}
                name="group"
            >
                {shapeProps.moduleJson.children.map((img, i) => {
                    const Ele = img.className;
                    if (Ele === 'Image' || Ele === 'videoSwiper') {
                        if (imgurl) {
                            return <Image key={i} {...img.attrs} image={imgurl} />
                        }
                        return <Fragment key={i}>
                            <Html divProps={{ style: { pointerEvents: "none" } }}>
                                <img src="../Images/icon/error.png" width={img.attrs.width} height={img.attrs.height} alt=""/>
                            </Html>
                            <Rect width={img.attrs.width} height={img.attrs.height} />
                        </Fragment>
                    }
                    if (Ele === 'Echart') {
                        const htmlId = 'Echart' + id;
                        return <Fragment key={i}>
                            <Html divProps={{ style: { pointerEvents: "none" } }}>
                                <div className="chart" id={htmlId} style={{ width: img.attrs.width, height: img.attrs.height }}></div>
                            </Html>
                            <Rect width={img.attrs.width} height={img.attrs.height} />
                        </Fragment>
                    }
                    if (Ele === 'wetHtml') {
                        return <Fragment key={i}>
                            <Html divProps={{ style: { pointerEvents: "none" } }}>
                                <div className="numstatus">
                                    <div>{img.attrs.text}</div>
                                    <span>23.3</span>
                                    <span>℃</span>
                                    <span>23.3</span>
                                    <span>%</span>
                                </div>
                            </Html>
                            <Rect width={img.attrs.width} height={img.attrs.height} />
                        </Fragment>
                    }
                    return <Ele key={i} {...img.attrs} />
                })}
            </Group>
            {isHoverHighlighted && (
                <Transformer
                    ref={hoverTransformRef}
                    borderStroke="#13c2c2"
                    borderStrokeWidth={hoverPulseOn ? 3 : 1}
                    anchorSize={0}
                    resizeEnabled={false}
                    rotateEnabled={false}
                    listening={false}
                    shouldOverdrawWholeArea={false}
                />
            )}
            {(isSelected || showSelectionFrame) && shapeProps.draggable && (
                <Transformer
                    borderStroke={isSelected ? '#1E9FFF' : '#52c41a'}
                    borderStrokeWidth={isSelected ? 2 : 1}
                    anchorSize={isSelected ? 8 : 0}
                    resizeEnabled={isSelected}
                    rotateEnabled={isSelected}
                    listening={isSelected}
                    ref={transformRef}
                    flipEnabled={false}
                    boundBoxFunc={(oldBox, newBox) => {
                        if (newBox.width < 5 || newBox.height < 5) {
                            return oldBox;
                        }
                        return newBox;
                    }}
                />
            )}
            {(isSelected || showSelectionFrame || isLockedHover) && !shapeProps.draggable && (
                <Transformer
                    borderStroke={isSelected ? '#ff4d4f' : '#ff7875'}
                    borderStrokeWidth={isSelected ? 5 : 1}
                    anchorSize={0}
                    resizeEnabled={false}
                    rotateEnabled={false}
                    listening={isSelected}
                    ref={transformRef}
                />
            )}
        </Fragment>
    );
};

export default ConElement;
